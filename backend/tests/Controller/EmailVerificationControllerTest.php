<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\EmailVerification;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;

class EmailVerificationControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->connection = static::getContainer()->get(Connection::class);

        if (!str_ends_with($this->connection->getDatabase(), '_test')) {
            throw new \RuntimeException('Ces tests nécessitent une base dédiée se terminant par _test.');
        }

        $this->connection->beginTransaction();
        static::getContainer()->get('limiter.email_verification')->create(hash('sha256', 'elodie@example.com'))->reset();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    private function register(): User
    {
        $this->client->jsonRequest('POST', '/api/register', [
            'firstName' => 'Élodie',
            'lastName' => "O'Connor",
            'email' => 'elodie@example.com',
            'password' => 'une phrase facile à retenir',
        ]);
        self::assertResponseStatusCodeSame(201);

        return static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'elodie@example.com']);
    }

    public function testRegistrationSendsAnActivationLinkWithoutPassword(): void
    {
        $user = $this->register();
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertSame('elodie@example.com', $message->getTo()[0]->getAddress());
        self::assertStringContainsString('/activation?', $message->getTextBody());
        self::assertStringNotContainsString('une phrase facile à retenir', $message->getTextBody());
        self::assertStringNotContainsString($user->getPassword(), $message->getTextBody());

        preg_match('~https?://[^\s]+~', $message->getTextBody(), $match);
        $this->client->request('GET', str_replace('/activation?', '/api/verify-email?', $match[0]));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('verified', json_decode($this->client->getResponse()->getContent(), true)['status']);
        self::assertNotNull($this->connection->fetchOne('SELECT email_verified_at FROM "user"'));

        $this->client->request('GET', str_replace('/activation?', '/api/verify-email?', $match[0]));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('already_verified', json_decode($this->client->getResponse()->getContent(), true)['status']);
    }

    public function testExpiredLinkLeavesEmailUnverified(): void
    {
        $user = $this->register();
        $verification = static::getContainer()->get(EmailVerification::class);
        $link = $verification->createUrl($user, new \DateTimeImmutable('-1 second'));
        $this->client->request('GET', str_replace('/activation?', '/api/verify-email?', $link));

        self::assertResponseStatusCodeSame(410);
        self::assertSame('expired_link', json_decode($this->client->getResponse()->getContent(), true)['status']);
        self::assertNull($this->connection->fetchOne('SELECT email_verified_at FROM "user"'));
    }

    public function testModifiedExpirationIsRejected(): void
    {
        $user = $this->register();
        $link = static::getContainer()->get(EmailVerification::class)->createUrl($user);
        $link = preg_replace('/_expiration=[0-9]+/', '_expiration=9999999999', $link);
        $this->client->request('GET', str_replace('/activation?', '/api/verify-email?', $link));

        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->connection->fetchOne('SELECT email_verified_at FROM "user"'));
    }

    public function testLinkCannotVerifyAChangedEmailAddress(): void
    {
        $user = $this->register();
        $link = static::getContainer()->get(EmailVerification::class)->createUrl($user);
        $user->setEmail('nouvelle@example.com');
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->client->request('GET', str_replace('/activation?', '/api/verify-email?', $link));

        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->connection->fetchOne('SELECT email_verified_at FROM "user"'));
    }

    public function testMissingSignatureIsRejected(): void
    {
        $user = $this->register();
        $this->client->request('GET', '/api/verify-email?id=' . $user->getId());

        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->connection->fetchOne('SELECT email_verified_at FROM "user"'));
    }

    public function testResendDoesNotCreateADuplicateAndIsRateLimited(): void
    {
        $this->register();
        $this->client->jsonRequest('POST', '/api/resend-verification', ['email' => ' ELODIE@example.com ']);
        self::assertResponseStatusCodeSame(202);
        self::assertEmailCount(1);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "user"'));

        $this->client->jsonRequest('POST', '/api/resend-verification', ['email' => 'elodie@example.com']);
        self::assertResponseStatusCodeSame(429);
        self::assertEmailCount(0);
    }

    public function testUnknownAndVerifiedAddressesDoNotReceiveAMail(): void
    {
        $this->client->jsonRequest('POST', '/api/resend-verification', ['email' => 'elodie@example.com']);
        self::assertResponseStatusCodeSame(202);
        self::assertEmailCount(0);
        $unknownResponse = $this->client->getResponse()->getContent();

        $user = $this->register();
        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        static::getContainer()->get('limiter.email_verification')->create(hash('sha256', 'elodie@example.com'))->reset();
        $this->client->jsonRequest('POST', '/api/resend-verification', ['email' => 'elodie@example.com']);

        self::assertResponseStatusCodeSame(202);
        self::assertEmailCount(0);
        self::assertSame($unknownResponse, $this->client->getResponse()->getContent());
    }

    public function testMailFailureDoesNotDeleteTheAccount(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')->willThrowException(new TransportException('Simulated failure'));
        static::getContainer()->set(MailerInterface::class, $mailer);
        $user = $this->register();

        $response = json_decode($this->client->getResponse()->getContent(), true);
        self::assertFalse($response['emailSent']);
        self::assertSame('pending_verification', $response['status']);
        self::assertNull($user->getEmailVerifiedAt());
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "user"'));
    }
}
