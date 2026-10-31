<?php

namespace App\Tests\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ScreeningControllerTest extends WebTestCase
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
            throw new \RuntimeException(
                'Ces tests nécessitent une base dédiée se terminant par _test.'
            );
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

    public function testPlanContainsOnlyTheRoomSeatsAndCurrentPrice(): void
    {
        [$screening, $seat, , $room] = $this->oneSeatScreening();
        $this->connection->executeStatement('INSERT INTO cinema_settings (id, ticket_price_cents) VALUES (1, 1050) ON CONFLICT (id) DO UPDATE SET ticket_price_cents = 1050');
        $otherRoom = $this->insert('room', ['name' => 'Autre salle', 'type' => 'Standard']);
        $this->insert('seat', ['room_id' => $otherRoom, 'row_label' => 'A', 'number' => 1]);
        $this->client->request('GET', "/api/screenings/$screening/seats");
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(1050, $data['unitPriceCents']);
        self::assertSame([['id' => $seat, 'row' => 'A', 'number' => 1, 'available' => true]], $data['seats']);
        self::assertSame('Salle test', $data['screening']['room']);
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertArrayNotHasKey('email', $data);
    }

    public function testOccupationIsSpecificToTheScreeningAndReleasedSeatsAreFree(): void
    {
        [$screening, $seat, $film, $room] = $this->oneSeatScreening();
        $this->connection->executeStatement('INSERT INTO cinema_settings (id, ticket_price_cents) VALUES (1, 1000) ON CONFLICT (id) DO NOTHING');
        $other = $this->screening($film, $room, '+2 days');
        $booking = $this->booking($screening, 'TEST-SEAT-PLAN');
        $this->insert('reserved_seat', ['reservation_id' => $booking, 'screening_id' => $screening, 'seat_id' => $seat]);
        $this->client->request('GET', "/api/screenings/$other/seats");
        self::assertTrue(json_decode($this->client->getResponse()->getContent(), true)['seats'][0]['available']);
        $this->client->request('GET', "/api/screenings/$screening/seats");
        self::assertFalse(json_decode($this->client->getResponse()->getContent(), true)['seats'][0]['available']);
        $this->connection->update('reserved_seat', ['released_at' => '2030-01-01 00:00:00'], ['reservation_id' => $booking]);
        $this->client->request('GET', "/api/screenings/$screening/seats");
        self::assertTrue(json_decode($this->client->getResponse()->getContent(), true)['seats'][0]['available']);
    }

    public function testUnavailableScreeningsAreRefused(): void
    {
        [$screening, , $film] = $this->oneSeatScreening();
        foreach ([['status' => 'cancelled'], ['status' => 'scheduled', 'starts_at' => '2000-01-01 12:00:00']] as $changes) {
            $this->connection->update('screening', $changes, ['id' => $screening]);
            $this->client->request('GET', "/api/screenings/$screening/seats");
            self::assertResponseStatusCodeSame(410);
        }
        $this->connection->update('film', ['is_showing' => 'false'], ['id' => $film]);
        $this->client->request('GET', "/api/screenings/$screening/seats");
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/screenings/2147483647/seats');
        self::assertResponseStatusCodeSame(404);
    }

    private function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $parameters = implode(', ', array_fill(0, count($data), '?'));

        return (int) $this->connection->fetchOne(
            "INSERT INTO $table ($columns) VALUES ($parameters) RETURNING id",
            array_values($data)
        );
    }

    private function film(string $title, bool $showing): int
    {
        return $this->insert('film', [
            'title' => $title,
            'synopsis' => 'Synopsis de test',
            'duration_minutes' => 110,
            'main_actors' => 'Actrice de test',
            'poster_url' => '/demo/posters/lumiere.svg',
            'poster_public_id' => 'demo/test',
            'is_showing' => $showing ? 'true' : 'false'
        ]);
    }

    private function screening(
        int $film,
        int $room,
        string $offset,
        string $status = 'scheduled'
    ): int {
        $start = new \DateTimeImmutable($offset, new \DateTimeZone('UTC'));

        return $this->insert('screening', [
            'film_id' => $film,
            'room_id' => $room,
            'status' => $status,
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $start->modify('+110 minutes')->format('Y-m-d H:i:s')
        ]);
    }

    private function oneSeatScreening(): array
    {
        $room = $this->insert('room', ['name' => 'Salle test', 'type' => 'Standard']);
        $seat = $this->insert('seat', ['room_id' => $room, 'row_label' => 'A', 'number' => 1]);
        $film = $this->film('Film de test', true);
        $screening = $this->screening($film, $room, '+1 day');

        return [$screening, $seat, $film, $room];
    }

    private function booking(int $screening, string $reference): int
    {
        return $this->insert('reservation', [
            'screening_id' => $screening,
            'reference' => $reference,
            'status' => 'confirmed',
            'first_name' => 'Client',
            'last_name' => 'Test',
            'email' => 'client@example.test',
            'unit_price_cents' => 1000,
            'total_amount_cents' => 1000,
            'created_at' => '2026-01-01 00:00:00'
        ]);
    }
}
