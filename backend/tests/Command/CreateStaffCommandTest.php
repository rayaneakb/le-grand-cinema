<?php

namespace App\Tests\Command;

use App\Command\CreateStaffCommand;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class CreateStaffCommandTest extends KernelTestCase
{
    private Connection $connection;

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get(Connection::class);
        if (!str_ends_with($this->connection->getDatabase(), '_test')) throw new \RuntimeException('Une base _test est obligatoire.');
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) $this->connection->rollBack();
        parent::tearDown();
    }

    public function testStaffPasswordIsHiddenValidatedAndHashedAndDuplicatesAreRefused(): void
    {
        $tester = new CommandTester(static::getContainer()->get(CreateStaffCommand::class));
        $password = 'une longue phrase de test';
        $tester->setInputs(['DIRECTION-test@example.com', 'Rayane', 'Akbly', 'Direction', 'court', $password]);
        $tester->execute([], ['interactive' => true]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'direction-test@example.com']);
        self::assertInstanceOf(User::class, $user);
        self::assertContains('ROLE_DIRECTOR', $user->getRoles());
        self::assertNotNull($user->getEmailVerifiedAt());
        self::assertNotSame($password, $user->getPassword());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, $password));
        self::assertStringNotContainsString($password, $tester->getDisplay());
        self::assertStringContainsString('15 à 128', $tester->getDisplay());
        $hash = $user->getPassword();
        $tester->setInputs(['direction-test@example.com', 'Autre', 'Compte', 'Employé']);
        $tester->execute([], ['interactive' => true]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame($hash, $user->getPassword());
        self::assertContains('ROLE_DIRECTOR', $user->getRoles());
    }
}
