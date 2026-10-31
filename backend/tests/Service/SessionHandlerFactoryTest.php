<?php

namespace App\Tests\Service;

use App\Service\SessionHandlerFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SessionHandlerFactoryTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    public function testSeparateHandlersCanReadAndDestroyTheSamePostgresSession(): void
    {
        self::bootKernel();
        $connection = static::getContainer()->get(Connection::class);
        if (!str_ends_with($connection->getDatabase(), '_test')) {
            throw new \RuntimeException('Ces tests nécessitent une base dédiée se terminant par _test.');
        }
        $id = bin2hex(random_bytes(24));
        $data = 'last_activity|i:' . time() . ';';
        $writer = SessionHandlerFactory::create($connection);
        $reader = SessionHandlerFactory::create($connection);

        try {
            $writer->open('', 'cinema_session');
            self::assertSame('', $writer->read($id));
            self::assertTrue($writer->write($id, $data));
            $writer->close();

            $reader->open('', 'cinema_session');
            self::assertSame($data, $reader->read($id));
            self::assertTrue($reader->destroy($id));
            $reader->close();
            self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM sessions WHERE sess_id = ?', [$id]));
        } finally {
            $writer->close();
            $reader->close();
            $connection->executeStatement('DELETE FROM sessions WHERE sess_id = ?', [$id]);
        }
    }
}
