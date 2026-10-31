<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ReservationControllerTest extends WebTestCase
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

    private function requestData(): array
    {
        [$screening, $seat, , $room] = $this->oneSeatScreening();
        $second = $this->insert('seat', ['room_id' => $room, 'row_label' => 'A', 'number' => 2]);
        $this->connection->executeStatement('INSERT INTO cinema_settings (id, ticket_price_cents) VALUES (1, 1050) ON CONFLICT (id) DO UPDATE SET ticket_price_cents = 1050');

        return [
            'screeningId' => $screening, 'seatIds' => [$seat, $second],
            'firstName' => ' Élodie ', 'lastName' => ' Martin ', 'email' => ' ELODIE@example.com ',
            'expectedUnitPriceCents' => 1050, 'confirmationKey' => 'c01b3fe3-43cc-4a59-8000-123456789abc',
        ];
    }

    private function confirm(array $data, bool $withCsrf = true): array
    {
        $this->client->request('GET', '/api/csrf');
        $token = json_decode($this->client->getResponse()->getContent(), true)['csrfToken'];
        $this->client->jsonRequest('POST', '/api/reservations', $data, $withCsrf ? ['HTTP_X_CSRF_TOKEN' => $token] : []);

        return json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testConfirmationSavesAllSeatsAndSnapshotsTheServerPrice(): void
    {
        $data = $this->requestData();
        $response = $this->confirm($data);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('confirmed', $response['status']);
        self::assertSame(2100, $response['reservation']['totalAmountCents']);
        self::assertSame(['A1', 'A2'], $response['reservation']['seats']);
        self::assertSame('Élodie', $response['reservation']['firstName']);
        self::assertSame('elodie@example.com', $response['reservation']['email']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reservation WHERE screening_id = ?', [$data['screeningId']]));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reserved_seat WHERE screening_id = ?', [$data['screeningId']]));
        $this->connection->update('cinema_settings', ['ticket_price_cents' => 2000], ['id' => 1]);
        $repeat = $this->confirm($data);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($repeat['replayed']);
        self::assertSame($response['reservation']['reference'], $repeat['reservation']['reference']);
        self::assertSame(2100, $repeat['reservation']['totalAmountCents']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reservation WHERE screening_id = ?', [$data['screeningId']]));
    }

    public function testAnOccupiedSeatRejectsTheWholeRequest(): void
    {
        $data = $this->requestData();
        $booking = $this->booking($data['screeningId'], 'ALREADY-TAKEN');
        $this->insert('reserved_seat', ['reservation_id' => $booking, 'screening_id' => $data['screeningId'], 'seat_id' => $data['seatIds'][0]]);
        $response = $this->confirm($data);
        self::assertResponseStatusCodeSame(409);
        self::assertSame([$data['seatIds'][0]], $response['unavailableSeatIds']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reservation WHERE screening_id = ?', [$data['screeningId']]));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reserved_seat WHERE seat_id = ?', [$data['seatIds'][1]]));
    }

    public function testAnotherRoomsSeatAndChangedPriceAreRefused(): void
    {
        $data = $this->requestData();
        $room = $this->insert('room', ['name' => 'Autre salle', 'type' => 'Standard']);
        $seat = $this->insert('seat', ['room_id' => $room, 'row_label' => 'A', 'number' => 1]);
        $this->confirm([...$data, 'seatIds' => [$seat]]);
        self::assertResponseStatusCodeSame(422);
        $response = $this->confirm([...$data, 'expectedUnitPriceCents' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('price_changed', $response['status']);
        self::assertSame(1050, $response['unitPriceCents']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reservation WHERE screening_id = ?', [$data['screeningId']]));
    }

    public function testInvalidFieldsAndCsrfNeverCreateAReservation(): void
    {
        $data = $this->requestData();
        $this->confirm($data, false);
        self::assertResponseStatusCodeSame(403);
        foreach ([
            ['seatIds' => []], ['seatIds' => [$data['seatIds'][0], $data['seatIds'][0]]],
            ['seatIds' => ['1']], ['firstName' => '   '], ['email' => 'invalide'],
            ['confirmationKey' => 'invalide'], ['totalAmountCents' => 1], ['userId' => 1],
        ] as $changes) {
            $this->confirm([...$data, ...$changes]);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reservation WHERE screening_id = ?', [$data['screeningId']]));
    }

    public function testAnIdempotencyKeyCannotBeReusedForAnotherRequest(): void
    {
        $data = $this->requestData();
        $this->confirm($data);
        self::assertResponseStatusCodeSame(201);
        $this->confirm([...$data, 'email' => 'other@example.com']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testCancelledAndPastScreeningsAreRefused(): void
    {
        $data = $this->requestData();
        $this->connection->update('screening', ['status' => 'cancelled'], ['id' => $data['screeningId']]);
        $this->confirm($data);
        self::assertResponseStatusCodeSame(410);
        $this->connection->update('screening', ['status' => 'scheduled', 'starts_at' => '2000-01-01 12:00:00'], ['id' => $data['screeningId']]);
        $this->confirm($data);
        self::assertResponseStatusCodeSame(410);
    }

    public function testOnlyTheMatchingVerifiedAccountIsLinked(): void
    {
        $data = $this->requestData();
        $user = (new User())->setFirstName('Élodie')->setLastName('Martin')
            ->setEmail('elodie@example.com')->setPassword('hash-de-test')
            ->setEmailVerifiedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $manager->persist($user);
        $manager->flush();
        $this->client->loginUser($user);
        $first = $this->confirm([...$data, 'seatIds' => [$data['seatIds'][0]]]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($user->getId(), (int) $this->connection->fetchOne('SELECT user_id FROM reservation WHERE reference = ?', [$first['reservation']['reference']]));
        $second = $this->confirm([
            ...$data, 'seatIds' => [$data['seatIds'][1]], 'email' => 'autre@example.com',
            'confirmationKey' => 'c01b3fe3-43cc-4a59-8000-abcdefabcdef',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->connection->fetchOne('SELECT user_id FROM reservation WHERE reference = ?', [$second['reservation']['reference']]));
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
