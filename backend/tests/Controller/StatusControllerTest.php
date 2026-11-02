<?php

namespace App\Tests\Controller;

use App\Controller\StatusController;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use PHPUnit\Framework\TestCase;

class StatusControllerTest extends TestCase
{
    public function testAvailableDatabaseReturnsOk(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT 1')
            ->willReturn(1);

        $response = (new StatusController())->index($connection);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok'], json_decode($response->getContent(), true));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testUnavailableDatabaseReturnsNoTechnicalDetails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT 1')
            ->willThrowException(new class('Internal connection details') extends \RuntimeException implements Exception {});

        $response = (new StatusController())->index($connection);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['status' => 'unavailable'], json_decode($response->getContent(), true));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }
}
