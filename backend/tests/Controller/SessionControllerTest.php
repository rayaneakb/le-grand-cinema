<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SessionControllerTest extends WebTestCase
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
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    private function user(bool $verified = true): User
    {
        $user = (new User())->setEmail('elodie@example.com')->setFirstName('Élodie')->setLastName('Martin');
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'une phrase facile à retenir'));
        if ($verified) {
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
        }
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $manager->persist($user);
        $manager->flush();

        return $user;
    }

    private function csrf(): string
    {
        $this->client->request('GET', '/api/csrf');
        self::assertResponseStatusCodeSame(200);

        return $this->data()['csrfToken'];
    }

    private function data(): array
    {
        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function login(string $token, string $password = 'une phrase facile à retenir'): void
    {
        $this->client->jsonRequest('POST', '/api/login', [
            'email' => ' ELODIE@example.com ',
            'password' => $password,
        ], ['HTTP_X_CSRF_TOKEN' => $token]);
    }

    public function testVerifiedAccountCanLoginAndSessionIdChanges(): void
    {
        $this->user();
        $token = $this->csrf();
        $oldId = $this->client->getRequest()->getSession()->getId();
        $this->login($token);
        self::assertResponseStatusCodeSame(200);
        self::assertNotSame($oldId, $this->client->getRequest()->getSession()->getId());

        $this->client->request('GET', '/api/session');
        self::assertSame('elodie@example.com', $this->data()['user']['email']);
        self::assertArrayNotHasKey('password', $this->data()['user']);
        self::assertArrayHasKey('logoutToken', $this->data());
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public function testWrongPasswordAndUnknownAccountHaveTheSameError(): void
    {
        $this->user();
        $token = $this->csrf();
        $this->login($token, 'un mauvais mot de passe');
        self::assertResponseStatusCodeSame(401);
        $error = $this->data();
        $this->client->jsonRequest('POST', '/api/login', [
            'email' => 'inconnu@example.com', 'password' => 'un mauvais mot de passe',
        ], ['HTTP_X_CSRF_TOKEN' => $token]);
        self::assertResponseStatusCodeSame(401);
        self::assertSame($error, $this->data());
    }

    public function testUnverifiedAccountIsRefusedAfterCorrectPasswordOnly(): void
    {
        $this->user(false);
        $token = $this->csrf();
        $this->login($token, 'un mauvais mot de passe');
        self::assertResponseStatusCodeSame(401);
        $this->login($token);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('email_not_verified', $this->data()['status']);
        $this->client->request('GET', '/api/session');
        self::assertNull($this->data()['user']);
    }

    public function testMissingCsrfTokenIsRefused(): void
    {
        $this->user();
        $this->csrf();
        $this->login('');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('invalid_csrf', $this->data()['status']);
    }

    public function testFiveFailuresPreventFurtherLoginAttempts(): void
    {
        $this->user();
        $token = $this->csrf();
        for ($i = 0; $i < 5; ++$i) {
            $this->login($token, 'un mauvais mot de passe');
            self::assertResponseStatusCodeSame(401);
        }
        $this->login($token);
        self::assertResponseStatusCodeSame(429);
        $this->client->request('GET', '/api/session');
        self::assertNull($this->data()['user']);
    }

    public function testLogoutInvalidatesTheSession(): void
    {
        $this->user();
        $this->login($this->csrf());
        $this->client->request('GET', '/api/session');
        $token = $this->data()['logoutToken'];
        $oldSessionId = $this->client->getRequest()->getSession()->getId();
        $this->client->request('POST', '/api/logout', ['_csrf_token' => $token]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('logged_out', $this->data()['status']);
        self::assertNotSame($oldSessionId, $this->client->getRequest()->getSession()->getId());
        $this->client->request('GET', '/api/session');
        self::assertNull($this->data()['user']);
    }

    public function testLogoutRequiresCsrfAndPost(): void
    {
        $this->user();
        $this->login($this->csrf());
        $this->client->request('POST', '/api/logout');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/logout');
        self::assertResponseStatusCodeSame(405);
        $this->client->request('GET', '/api/session');
        self::assertNotNull($this->data()['user']);
    }

    public function testSessionExpiresAfterTenMinutesAndFilmsDoNotKeepItAlive(): void
    {
        $this->user();
        $this->login($this->csrf());
        $session = $this->client->getRequest()->getSession();
        $lastActivity = time() - 500;
        $session->set('last_activity', $lastActivity);
        $session->save();
        $this->client->request('GET', '/api/films');
        self::assertResponseStatusCodeSame(200);
        self::assertSame($lastActivity, $this->client->getRequest()->getSession()->get('last_activity'));

        $session = $this->client->getRequest()->getSession();
        $session->set('last_activity', time() - 601);
        $session->save();
        $this->client->request('GET', '/api/session');
        self::assertNull($this->data()['user']);
    }

    public function testActivityRequiresCsrfAndRefreshesTheInactivityTimer(): void
    {
        $this->user();
        $this->login($this->csrf());
        $this->client->request('GET', '/api/session');
        $csrf = $this->data()['csrfToken'];
        $session = $this->client->getRequest()->getSession();
        $session->set('last_activity', time() - 500);
        $session->save();
        $this->client->request('POST', '/api/activity');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/api/activity', server: ['HTTP_X_CSRF_TOKEN' => $csrf]);
        self::assertResponseStatusCodeSame(200);
        self::assertGreaterThan(time() - 5, $this->client->getRequest()->getSession()->get('last_activity'));
    }
}
