<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class RegistrationControllerTest extends WebTestCase
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
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    private function validData(): array
    {
        return [
            'firstName' => ' Élodie ',
            'lastName' => "O'Connor",
            'email' => ' ELODIE@example.com ',
            'password' => 'une phrase facile à retenir',
        ];
    }

    #[DataProvider('passwords')]
    public function testClientIsSavedWithHashedPasswordAndUnverifiedEmail(string $password): void
    {
        $data = $this->validData();
        $data['password'] = $password;
        $this->client->jsonRequest('POST', '/api/register', $data);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            ['status' => 'pending_verification', 'emailSent' => true],
            json_decode($this->client->getResponse()->getContent(), true),
        );

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'elodie@example.com']);
        self::assertInstanceOf(User::class, $user);
        self::assertSame('Élodie', $user->getFirstName());
        self::assertSame("O'Connor", $user->getLastName());
        self::assertSame(['ROLE_USER'], $user->getRoles());
        self::assertNull($user->getEmailVerifiedAt());
        self::assertNotSame($password, $user->getPassword());

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, $password));
        self::assertFalse($hasher->isPasswordValid($user, $password . 'x'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "user"'));
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
    }

    public static function passwords(): array
    {
        return [
            ['une phrase facile à retenir'],
            [str_repeat('é', 128)],
            ['  une phrase avec des espaces  '],
        ];
    }

    public function testDuplicateEmailDoesNotReplaceExistingPassword(): void
    {
        $data = $this->validData();
        $this->client->jsonRequest('POST', '/api/register', $data);
        self::assertResponseStatusCodeSame(201);
        $hash = $this->connection->fetchOne('SELECT password FROM "user"');

        $data['email'] = 'elodie@EXAMPLE.COM';
        $data['password'] = 'un autre mot de passe';
        $this->client->jsonRequest('POST', '/api/register', $data);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "user"'));
        self::assertSame($hash, $this->connection->fetchOne('SELECT password FROM "user"'));
    }

    public function testInvalidFieldsAndAdminRoleAreRejectedWithoutSaving(): void
    {
        $data = $this->validData();
        $data['email'] = 'mail-invalide';
        $data['password'] = 'court';
        $data['roles'] = ['ROLE_ADMIN'];
        $this->client->jsonRequest('POST', '/api/register', $data);

        self::assertResponseStatusCodeSame(422);
        $errors = json_decode($this->client->getResponse()->getContent(), true)['errors'];
        self::assertArrayHasKey('email', $errors);
        self::assertArrayHasKey('password', $errors);
        self::assertArrayHasKey('roles', $errors);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "user"'));
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidJsonIsRejected(string $body): void
    {
        $this->client->request('POST', '/api/register', server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "user"'));
    }

    public static function invalidBodies(): array
    {
        return [['{'], ['[]'], ['null'], ['"texte"']];
    }

    public function testFormEncodedRequestIsRejected(): void
    {
        $this->client->request('POST', '/api/register', $this->validData());

        self::assertResponseStatusCodeSame(415);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM "user"'));
    }

    public function testGetIsNotAllowed(): void
    {
        $this->client->request('GET', '/api/register');

        self::assertResponseStatusCodeSame(405);
    }
}
