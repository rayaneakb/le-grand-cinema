<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AdminControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private int $booking;
    private int $screening;

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
            throw new \RuntimeException('Une base dédiée _test est obligatoire.');
        }
        $this->connection->beginTransaction();
        $this->connection->executeStatement('INSERT INTO cinema_settings (id, ticket_price_cents) VALUES (1, 1000) ON CONFLICT (id) DO UPDATE SET ticket_price_cents = 1000');
        $room = $this->insert('room', ['name' => 'Salle statistiques', 'type' => 'Standard']);
        $this->connection->executeStatement("INSERT INTO seat (room_id, row_label, number) SELECT ?, 'A', generate_series(1, 180)", [$room]);
        $film = $this->insert('film', [
            'title' => 'Film statistiques', 'synopsis' => 'Synopsis', 'duration_minutes' => 100,
            'main_actors' => 'Actrice', 'poster_url' => '/demo/posters/lumiere.svg', 'poster_public_id' => 'demo/test', 'is_showing' => 'true',
        ]);
        $this->screening = $this->insert('screening', [
            'film_id' => $film, 'room_id' => $room, 'status' => 'scheduled',
            'starts_at' => '2030-01-01 19:00:00', 'ends_at' => '2030-01-01 20:40:00',
        ]);
        $this->booking = $this->reservation('ABCDEF0123456789', 'confirmed');
        $this->connection->executeStatement('INSERT INTO reserved_seat (reservation_id, screening_id, seat_id) SELECT ?, ?, id FROM seat WHERE room_id = ? AND number <= 86', [$this->booking, $this->screening, $room]);
        $cancelled = $this->reservation('FFFFFFFFFFFFFFFF', 'cancelled');
        $this->connection->executeStatement("INSERT INTO reserved_seat (reservation_id, screening_id, seat_id, released_at) SELECT ?, ?, id, '2029-01-01 00:00:00' FROM seat WHERE room_id = ? AND number <= 2", [$cancelled, $this->screening, $room]);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) $this->connection->rollBack();
        parent::tearDown();
    }

    private function login(string $role): void
    {
        $user = (new User())->setEmail(strtolower($role) . '@example.com')->setFirstName('Compte')->setLastName('Test')
            ->setRoles([$role])->setPassword('hash-test')->setEmailVerifiedAt(new \DateTimeImmutable());
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $manager->persist($user);
        $manager->flush();
        $this->client->loginUser($user);
    }

    private function tariff(string $price, bool $csrf = true): array
    {
        $this->client->request('GET', '/api/csrf');
        $token = json_decode($this->client->getResponse()->getContent(), true)['csrfToken'];
        $this->client->jsonRequest('POST', '/api/admin/settings/tariff', ['price' => $price], $csrf ? ['HTTP_X_CSRF_TOKEN' => $token] : []);

        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testVisitorAndClientCannotAccessStaffData(): void
    {
        $this->client->request('GET', '/api/admin/settings');
        self::assertResponseStatusCodeSame(401);
        $this->login('ROLE_USER');
        foreach (['/api/admin/settings', '/api/admin/reservations?q=client@example.com', '/api/admin/statistics?from=2030-01-01&to=2030-01-01'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
        }
        $this->tariff('12');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1000, (int) $this->connection->fetchOne('SELECT ticket_price_cents FROM cinema_settings WHERE id = 1'));
    }

    public function testEmployeeCanSearchButCannotChangePriceOrReadStatistics(): void
    {
        $this->login('ROLE_EMPLOYEE');
        $this->client->request('GET', '/api/admin/reservations?q=abcdef0123456789');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data['reservations']);
        self::assertSame('client@example.com', $data['reservations'][0]['email']);
        self::assertCount(86, $data['reservations'][0]['seats']);
        $this->client->request('GET', '/api/admin/reservations?q=CLIENT@example.com');
        self::assertCount(2, json_decode($this->client->getResponse()->getContent(), true)['reservations']);
        $this->tariff('12');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/admin/statistics?from=2030-01-01&to=2030-01-01');
        self::assertResponseStatusCodeSame(403);
    }

    public function testDirectorInheritsEmployeeRightsAndPriceIsSnapshotSafe(): void
    {
        $this->login('ROLE_DIRECTOR');
        $this->client->request('GET', '/api/admin/reservations?q=ABCDEF0123456789');
        self::assertResponseIsSuccessful();
        $data = $this->tariff('10,50');
        self::assertResponseIsSuccessful();
        self::assertSame(1050, $data['ticketPriceCents']);
        self::assertSame(1050, (int) $this->connection->fetchOne('SELECT ticket_price_cents FROM cinema_settings WHERE id = 1'));
        self::assertSame(1000, (int) $this->connection->fetchOne('SELECT unit_price_cents FROM reservation WHERE id = ?', [$this->booking]));
        self::assertSame(86000, (int) $this->connection->fetchOne('SELECT total_amount_cents FROM reservation WHERE id = ?', [$this->booking]));
        $this->tariff('11', false);
        self::assertResponseStatusCodeSame(403);
    }

    public function testInvalidPricesNeverChangeTheTariff(): void
    {
        $this->login('ROLE_DIRECTOR');
        foreach (['0', '-5', '10.999', 'abc', '1e2', '21474836.48'] as $price) {
            $this->tariff($price);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame(1000, (int) $this->connection->fetchOne('SELECT ticket_price_cents FROM cinema_settings WHERE id = 1'));
        $this->tariff('10,5');
        self::assertResponseIsSuccessful();
        self::assertSame(1050, (int) $this->connection->fetchOne('SELECT ticket_price_cents FROM cinema_settings WHERE id = 1'));
    }

    public function testStatisticsUseScreeningDatesAndCountSeatsRatherThanBookings(): void
    {
        $this->login('ROLE_DIRECTOR');
        $this->client->request('GET', '/api/admin/statistics?from=2030-01-01&to=2030-01-01');
        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame(1, $data['totals']['confirmedReservations']);
        self::assertSame(1, $data['totals']['cancelledReservations']);
        self::assertSame(86, $data['totals']['bookedSeats']);
        self::assertSame(47.8, $data['totals']['occupancyRate']);
        self::assertSame(180, $data['screenings'][0]['capacity']);
        $this->client->request('GET', '/api/admin/statistics?from=2026-01-01&to=2026-01-01');
        $empty = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame([], $empty['screenings']);
        self::assertSame(0, $empty['totals']['bookedSeats']);
        self::assertNull($empty['totals']['occupancyRate']);
        $this->connection->update('screening', ['status' => 'cancelled'], ['id' => $this->screening]);
        $this->client->request('GET', '/api/admin/statistics?from=2030-01-01&to=2030-01-01');
        self::assertSame([], json_decode($this->client->getResponse()->getContent(), true)['screenings']);
    }

    public function testInvalidPeriodAndEmptySearchAreRefused(): void
    {
        $this->login('ROLE_DIRECTOR');
        foreach (['from=2030-02-30&to=2030-03-01', 'from=2030-02-01&to=2030-01-01', 'from=2020-01-01&to=2030-01-01'] as $query) {
            $this->client->request('GET', '/api/admin/statistics?' . $query);
            self::assertResponseStatusCodeSame(422);
        }
        $this->client->request('GET', '/api/admin/reservations?q=');
        self::assertResponseStatusCodeSame(422);
        $this->client->request('GET', '/api/admin/reservations?q=INCONNU');
        self::assertSame([], json_decode($this->client->getResponse()->getContent(), true)['reservations']);
    }

    private function reservation(string $reference, string $status): int
    {
        return $this->insert('reservation', [
            'reference' => $reference, 'screening_id' => $this->screening, 'first_name' => 'Client', 'last_name' => 'Test',
            'email' => 'client@example.com', 'status' => $status, 'unit_price_cents' => 1000, 'total_amount_cents' => 86000,
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function insert(string $table, array $data): int
    {
        return (int) $this->connection->fetchOne('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ') RETURNING id', array_values($data));
    }
}
