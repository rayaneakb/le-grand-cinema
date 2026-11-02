<?php

namespace App\Tests\Service;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

class ReservationConcurrencyTest extends KernelTestCase
{
    private Connection $connection;
    private array $processes = [];
    private array $ids = [];
    private bool $createdSettings = false;

    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get(Connection::class);
        if (!str_ends_with($this->connection->getDatabase(), '_test')) {
            throw new \RuntimeException('Une base de test dédiée est obligatoire.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        if (isset($this->connection)) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            if (isset($this->ids['screening'])) {
                $this->connection->delete('reserved_seat', ['screening_id' => $this->ids['screening']]);
                $this->connection->delete('reservation', ['screening_id' => $this->ids['screening']]);
                $this->connection->delete('screening', ['id' => $this->ids['screening']]);
            }
            if (isset($this->ids['room'])) {
                $this->connection->delete('seat', ['room_id' => $this->ids['room']]);
                $this->connection->delete('room', ['id' => $this->ids['room']]);
            }
            if (isset($this->ids['film'])) {
                $this->connection->delete('film', ['id' => $this->ids['film']]);
            }
            if ($this->createdSettings) {
                $this->connection->delete('cinema_settings', ['id' => 1]);
            }
        }
        parent::tearDown();
    }

    public function testTwoConcurrentClientsCannotReserveTheSameSeat(): void
    {
        $results = $this->race(false);
        $statuses = array_column($results, 'status');
        sort($statuses);
        self::assertSame(['confirmed', 'refused'], $statuses);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reservation WHERE screening_id = ?', [$this->ids['screening']]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reserved_seat WHERE screening_id = ? AND released_at IS NULL', [$this->ids['screening']]));
    }

    public function testTwoConcurrentRetriesReturnTheSameReservation(): void
    {
        $results = $this->race(true);
        self::assertSame(['confirmed', 'confirmed'], array_column($results, 'status'));
        self::assertSame($results[0]['reservation']['reference'], $results[1]['reservation']['reference']);
        self::assertNotSame($results[0]['replayed'], $results[1]['replayed']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reservation WHERE screening_id = ?', [$this->ids['screening']]));
    }

    private function race(bool $sameKey): array
    {
        $suffix = bin2hex(random_bytes(6));
        $this->ids['room'] = $this->insert('room', ['name' => 'Concurrence ' . $suffix, 'type' => 'Standard']);
        $this->ids['film'] = $this->insert('film', [
            'title' => 'Concurrence ' . $suffix, 'synopsis' => 'Test', 'main_actors' => 'Test',
            'duration_minutes' => 90, 'poster_url' => '/demo/test.svg',
            'poster_public_id' => 'test', 'is_showing' => 'true',
        ]);
        $start = new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC'));
        $this->ids['screening'] = $this->insert('screening', [
            'room_id' => $this->ids['room'], 'film_id' => $this->ids['film'], 'status' => 'scheduled',
            'starts_at' => $start->format('Y-m-d H:i:s'), 'ends_at' => $start->modify('+90 minutes')->format('Y-m-d H:i:s'),
        ]);
        $seat = $this->insert('seat', ['room_id' => $this->ids['room'], 'row_label' => 'A', 'number' => 1]);
        $this->createdSettings = $this->connection->executeStatement('INSERT INTO cinema_settings (id, ticket_price_cents) VALUES (1, 1000) ON CONFLICT DO NOTHING') === 1;
        $price = (int) $this->connection->fetchOne('SELECT ticket_price_cents FROM cinema_settings WHERE id = 1');
        $data = [
            'screeningId' => $this->ids['screening'], 'seatIds' => [$seat], 'firstName' => 'Client',
            'lastName' => 'Test', 'email' => 'client@example.com', 'expectedUnitPriceCents' => $price,
        ];

        // Les deux processus attendent la même séance avant que le parent les libère.
        $this->connection->beginTransaction();
        $this->connection->fetchOne('SELECT id FROM screening WHERE id = ? FOR UPDATE', [$this->ids['screening']]);
        $key = hash('sha256', $suffix);
        for ($index = 0; $index < 2; ++$index) {
            $process = new Process([
                PHP_BINARY, dirname(__DIR__) . '/Support/confirm-reservation.php',
                json_encode($data, JSON_THROW_ON_ERROR), $sameKey ? $key : hash('sha256', $suffix . $index),
            ], dirname(__DIR__, 2), ['APP_ENV' => 'test', 'APP_DEBUG' => '1']);
            $process->setTimeout(15);
            $process->start();
            $this->processes[] = $process;
            self::assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY')), $process->getErrorOutput());
        }
        $this->connection->commit();
        $results = [];
        foreach ($this->processes as $process) {
            $process->wait();
            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $results[] = json_decode(substr($process->getOutput(), strpos($process->getOutput(), "\n") + 1), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    private function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $parameters = implode(', ', array_fill(0, count($data), '?'));

        return (int) $this->connection->fetchOne("INSERT INTO $table ($columns) VALUES ($parameters) RETURNING id", array_values($data));
    }
}
