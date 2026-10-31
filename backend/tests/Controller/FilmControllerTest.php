<?php

namespace App\Tests\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FilmControllerTest extends WebTestCase
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

    public function testEmptyProgrammeIsAccessibleWithoutLogin(): void
    {
        $data = $this->programme();
        self::assertSame([], $data['films']);
        self::assertArrayHasKey('generatedAt', $data);
    }

    public function testOnlyShowingFilmsAndFutureScheduledScreeningsAreReturned(): void
    {
        $room = $this->insert('room', ['name' => 'Salle test', 'type' => 'Standard']);
        $film = $this->film('Film visible', true);
        $withoutScreening = $this->film('Film sans séance', true);
        $this->film('Film masqué', false);
        $future = $this->screening($film, $room, '+1 day');
        $this->screening($film, $room, '-1 day');
        $this->screening($film, $room, '+2 days', 'cancelled');

        $data = $this->programme();
        self::assertCount(2, $data['films']);
        $films = array_column($data['films'], null, 'id');
        self::assertSame([], $films[$withoutScreening]['screenings']);
        self::assertSame([$future], array_column($films[$film]['screenings'], 'id'));
        self::assertSame(110, $films[$film]['durationMinutes']);
        self::assertSame('Synopsis de test', $films[$film]['synopsis']);
        self::assertSame('Actrice de test', $films[$film]['mainActors']);
        self::assertSame('/demo/posters/lumiere.svg', $films[$film]['posterUrl']);
        self::assertStringEndsWith('+00:00', $films[$film]['screenings'][0]['startsAt']);
        self::assertArrayNotHasKey('email', $films[$film]);
    }

    public function testReleasedSeatsBecomeAvailableAgain(): void
    {
        [$screening, $seat] = $this->oneSeatScreening();
        $booking = $this->booking($screening, 'TEST-RELEASE');
        $this->insert('reserved_seat', [
            'reservation_id' => $booking,
            'screening_id' => $screening,
            'seat_id' => $seat
        ]);

        $data = $this->programme();
        self::assertSame(0, $data['films'][0]['screenings'][0]['availableSeats']);
        self::assertTrue($data['films'][0]['screenings'][0]['full']);

        $this->connection->update('reservation', ['status' => 'cancelled'], ['id' => $booking]);
        $this->connection->update(
            'reserved_seat',
            ['released_at' => '2026-01-01 00:00:00'],
            ['reservation_id' => $booking]
        );
        $data = $this->programme();
        self::assertSame(1, $data['films'][0]['screenings'][0]['availableSeats']);
        self::assertFalse($data['films'][0]['screenings'][0]['full']);
    }

    public function testAnotherScreeningsBookingsDoNotOccupyTheSeat(): void
    {
        [$screening, $seat, $film, $room] = $this->oneSeatScreening();
        $other = $this->screening($film, $room, '+2 days');
        $booking = $this->booking($other, 'TEST-OTHER');
        $this->insert('reserved_seat', [
            'reservation_id' => $booking,
            'screening_id' => $other,
            'seat_id' => $seat
        ]);
        $data = $this->programme();
        $screenings = array_column($data['films'][0]['screenings'], null, 'id');
        self::assertSame(1, $screenings[$screening]['availableSeats']);
        self::assertSame(0, $screenings[$other]['availableSeats']);
    }

    public function testPostIsNotAllowed(): void
    {
        $this->client->request('POST', '/api/films');
        self::assertResponseStatusCodeSame(405);
    }

    private function programme(): array
    {
        $this->client->request('GET', '/api/films');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertStringContainsString(
            'no-store',
            $this->client->getResponse()->headers->get('Cache-Control')
        );

        return json_decode(
            $this->client->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR
        );
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
