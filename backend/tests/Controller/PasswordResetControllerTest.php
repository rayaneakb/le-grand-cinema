<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\PasswordReset;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class PasswordResetControllerTest extends WebTestCase
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
        $this->client->catchExceptions(false);
        $this->connection = static::getContainer()->get(Connection::class);
        if (!str_ends_with($this->connection->getDatabase(), '_test')) {
            throw new \RuntimeException('Ces tests nécessitent une base dédiée se terminant par _test.');
        }
        $this->connection->beginTransaction();
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    private function user(bool $verified = true, array $roles = []): User
    {
        $user = (new User())->setEmail('elodie@example.com')->setFirstName('Élodie')->setLastName('Martin')->setRoles($roles);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ancien mot de passe'));
        if ($verified) {
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
        }
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $manager->persist($user);
        $manager->flush();

        return $user;
    }

    private function data(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testRequestDoesNotRevealWhetherAccountExists(): void
    {
        $this->user();
        $this->client->jsonRequest('POST', '/api/forgot-password', ['email' => 'inconnu@example.com']);
        self::assertResponseStatusCodeSame(202);
        self::assertEmailCount(0);
        $genericResponse = $this->data();

        $this->client->jsonRequest('POST', '/api/forgot-password', ['email' => ' ELODIE@example.com ']);
        self::assertResponseStatusCodeSame(202);
        self::assertSame($genericResponse, $this->data());
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertStringContainsString('/reinitialiser-mot-de-passe?', $mail->getTextBody());
        self::assertStringNotContainsString('ancien mot de passe', $mail->getTextBody());
    }

    public function testUnverifiedAccountDoesNotReceiveResetMail(): void
    {
        $this->user(false);
        $this->client->jsonRequest('POST', '/api/forgot-password', ['email' => 'elodie@example.com']);
        self::assertResponseStatusCodeSame(202);
        self::assertEmailCount(0);
    }

    public function testReceivedLinkChangesPasswordOnceAndInvalidatesAnExistingSession(): void
    {
        $user = $this->user();
        $this->client->request('GET', '/api/csrf');
        $csrf = $this->data()['csrfToken'];
        $this->client->jsonRequest('POST', '/api/login', ['email' => 'elodie@example.com', 'password' => 'ancien mot de passe'], ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(200);
        $oldCookies = $this->client->getCookieJar()->all();
        $this->client->jsonRequest('POST', '/api/forgot-password', ['email' => 'elodie@example.com']);
        preg_match('~https?://[^\s]+~', self::getMailerMessage()->getTextBody(), $match);
        $url = $match[0];
        $this->client->jsonRequest('POST', '/api/reset-password', ['url' => $url, 'password' => 'mon nouveau mot de passe']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('password_changed', $this->data()['status']);

        $this->client->request('GET', '/api/session');
        self::assertNull($this->data()['user']);
        $this->client->getCookieJar()->clear();
        foreach ($oldCookies as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
        $this->client->request('GET', '/api/session');
        self::assertNull($this->data()['user']);

        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $user = $manager->find(User::class, $user->getId());
        $manager->refresh($user);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, 'mon nouveau mot de passe'));
        self::assertFalse($hasher->isPasswordValid($user, 'ancien mot de passe'));

        $this->client->jsonRequest('POST', '/api/reset-password', ['url' => $url, 'password' => 'encore un mot de passe']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame('invalid_link', $this->data()['status']);
    }

    public function testExpiredAndModifiedLinksAreRejected(): void
    {
        $user = $this->user();
        $reset = static::getContainer()->get(PasswordReset::class);
        $expired = $reset->createUrl($user, new \DateTimeImmutable('-1 second'));
        $this->client->jsonRequest('POST', '/api/reset-password', ['url' => $expired, 'password' => 'mon nouveau mot de passe']);
        self::assertResponseStatusCodeSame(400);
        $modified = preg_replace('/_expiration=[0-9]+/', '_expiration=9999999999', $reset->createUrl($user));
        $this->client->jsonRequest('POST', '/api/reset-password', ['url' => $modified, 'password' => 'mon nouveau mot de passe']);
        self::assertResponseStatusCodeSame(400);
        self::assertSame($user->getPassword(), $this->connection->fetchOne('SELECT password FROM "user"'));
    }

    public function testTooShortPasswordDoesNotConsumeTheLink(): void
    {
        $user = $this->user();
        $url = static::getContainer()->get(PasswordReset::class)->createUrl($user);
        $this->client->jsonRequest('POST', '/api/reset-password', ['url' => $url, 'password' => 'court']);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/reset-password', ['url' => $url, 'password' => 'mon nouveau mot de passe']);
        self::assertResponseStatusCodeSame(200);
    }

    public function testStaffPasswordRequiresFifteenCharacters(): void
    {
        $user = $this->user(true, ['ROLE_EMPLOYEE']);
        $url = static::getContainer()->get(PasswordReset::class)->createUrl($user);
        $this->client->jsonRequest('POST', '/api/reset-password', ['url' => $url, 'password' => 'quatorze-carac']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('15 caractères', $this->data()['message']);
        $this->client->jsonRequest('POST', '/api/reset-password', ['url' => $url, 'password' => 'une phrase assez longue']);
        self::assertResponseStatusCodeSame(200);
    }

    public function testRepeatedRequestsAreRateLimited(): void
    {
        $this->user();
        $this->client->jsonRequest('POST', '/api/forgot-password', ['email' => 'elodie@example.com']);
        self::assertResponseStatusCodeSame(202);
        $this->client->jsonRequest('POST', '/api/forgot-password', ['email' => 'elodie@example.com']);
        self::assertResponseStatusCodeSame(429);
        self::assertEmailCount(0);
    }

    public function testMailFailureDoesNotChangePassword(): void
    {
        $user = $this->user();
        $oldHash = $user->getPassword();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')->willThrowException(new TransportException('Simulated failure'));
        static::getContainer()->set(MailerInterface::class, $mailer);
        $this->client->jsonRequest('POST', '/api/forgot-password', ['email' => 'elodie@example.com']);
        self::assertResponseStatusCodeSame(503);
        self::assertSame($oldHash, $this->connection->fetchOne('SELECT password FROM "user"'));
    }
}
