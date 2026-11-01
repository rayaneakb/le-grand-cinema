<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Exception\TransportException;

class AdminScreeningControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private MockClock $clock;
    private int $film;
    private int $room;
    private array $sent = [];
    private bool $mailFails = false;

    protected static function getKernelClass(): string { return \App\Kernel::class; }

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->db = self::getContainer()->get(Connection::class);
        if (!str_ends_with($this->db->getDatabase(), '_test')) throw new \RuntimeException('Base _test obligatoire.');
        $this->db->beginTransaction();
        $this->clock = new MockClock('2030-01-01 12:00:00 UTC');
        self::getContainer()->set(ClockInterface::class, $this->clock);
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function ($message): void {
            if ($this->mailFails) throw new TransportException('Service mail simulé indisponible.');
            $this->sent[] = $message;
        });
        self::getContainer()->set(MailerInterface::class, $mailer);
        $this->film = (int) $this->db->fetchOne("INSERT INTO film (title, synopsis, duration_minutes, main_actors, poster_url, poster_public_id, is_showing) VALUES ('Film séances', 'Synopsis', 178, 'Acteurs', '/image.png', 'demo/test', TRUE) RETURNING id");
        $this->room = (int) $this->db->fetchOne("INSERT INTO room (name, type) VALUES ('Salle séances tests', 'Standard') RETURNING id");
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->isTransactionActive()) $this->db->rollBack();
        parent::tearDown();
    }

    private function login(string $role = 'ROLE_EMPLOYEE'): void
    {
        $user = (new User())->setEmail(uniqid('screening') . '@example.com')->setFirstName('Staff')->setLastName('Test')->setPassword('hash-test')->setRoles([$role])->setEmailVerifiedAt(new \DateTimeImmutable());
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $manager->persist($user);
        $manager->flush();
        $this->client->loginUser($user);
    }

    private function save(string $start, ?int $id = null, array $extra = [], bool $csrf = true): array
    {
        return $this->write($id ? '/' . $id : '', ['filmId' => $this->film, 'roomId' => $this->room, 'startsAt' => $start, ...$extra], $csrf);
    }

    private function write(string $path, array $data = [], bool $csrf = true): array
    {
        $this->client->request('GET', '/api/csrf');
        $token = json_decode($this->client->getResponse()->getContent(), true)['csrfToken'];
        $this->client->jsonRequest('POST', '/api/admin/screenings' . $path, $data, ['HTTP_X_CSRF_TOKEN' => $csrf ? $token : 'invalide']);
        return json_decode($this->client->getResponse()->getContent(), true) ?? [];
    }

    private function book(int $session): int
    {
        $booking = (int) $this->db->fetchOne("INSERT INTO reservation (reference, screening_id, first_name, last_name, email, status, unit_price_cents, total_amount_cents, created_at) VALUES (?, ?, 'Client', 'Test', 'client@example.com', 'confirmed', 1000, 1000, '2030-01-01') RETURNING id", [strtoupper(bin2hex(random_bytes(8))), $session]);
        $seat = (int) $this->db->fetchOne("INSERT INTO seat (room_id, row_label, number) VALUES (?, 'A', 1) RETURNING id", [$this->room]);
        $this->db->insert('reserved_seat', ['reservation_id' => $booking, 'screening_id' => $session, 'seat_id' => $seat]);
        return $booking;
    }

    public function testClientCannotAccessOrCreateAndEmployeeNeedsCsrf(): void
    {
        $this->login('ROLE_USER');
        $this->client->request('GET', '/api/admin/screenings?date=2030-01-02');
        self::assertResponseStatusCodeSame(403);
        $this->save('2030-01-02T14:00');
        self::assertResponseStatusCodeSame(403);
    }

    public function testDurationParisTimeAndOneHourMargin(): void
    {
        $this->login();
        $first = $this->save('2030-01-02T14:00');
        self::assertResponseStatusCodeSame(201);
        self::assertSame('2030-01-02 15:58:00', $this->db->fetchOne('SELECT ends_at FROM screening WHERE id = ?', [$first['id']]));
        $otherFilm = (int) $this->db->fetchOne("INSERT INTO film (title, synopsis, duration_minutes, main_actors, poster_url, poster_public_id, is_showing) VALUES ('Autre film', 'Synopsis', 112, 'Acteurs', '/image.png', 'demo/autre', TRUE) RETURNING id");
        $this->save('2030-01-02T15:57', extra: ['filmId' => $otherFilm]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) FROM screening WHERE room_id = ?', [$this->room]));
        $this->save('2030-01-02T17:57');
        self::assertResponseStatusCodeSame(409);
        $this->save('2030-01-02T17:58');
        self::assertResponseStatusCodeSame(201);
        $this->client->request('GET', '/api/admin/screenings?date=2030-01-02');
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(2, $data['screenings']);
        self::assertSame('2030-01-02T13:00:00+00:00', $data['screenings'][0]['startsAt']);
    }

    public function testOverlapInAnotherRoomIsAllowedAndInvalidDatesAreRejected(): void
    {
        $this->login('ROLE_DIRECTOR');
        $this->save('2030-01-02T14:00');
        $room = (int) $this->db->fetchOne("INSERT INTO room (name, type) VALUES ('Autre salle test', 'Standard') RETURNING id");
        $this->save('2030-01-02T14:00', extra: ['roomId' => $room]);
        self::assertResponseStatusCodeSame(201);
        foreach (['2029-12-31T14:00', '2030-02-30T14:00', '2030-03-31T02:30', "2030-01-02T14:00\0"] as $start) {
            $this->save($start);
            self::assertResponseStatusCodeSame(422);
        }
        $this->save('2030-01-03T14:00', csrf: false);
        self::assertResponseStatusCodeSame(403);
    }

    public function testReservedScreeningCannotChangeFilmOrRoomAndChecksBoth24HourLimits(): void
    {
        $this->login();
        $session = $this->save('2030-01-03T14:00')['id'];
        $this->book($session);
        $room = (int) $this->db->fetchOne("INSERT INTO room (name, type) VALUES ('Salle interdite', 'Standard') RETURNING id");
        $this->save('2030-01-03T14:00', $session, ['roomId' => $room]);
        self::assertResponseStatusCodeSame(409);
        $this->save('2030-01-02T12:59', $session);
        self::assertResponseStatusCodeSame(409);
        $this->save('2030-01-02T13:00', $session);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->sent);
        self::assertStringContainsString('02/01/2030 à 13:00', $this->sent[0]->getTextBody());
        $this->clock->sleep(1);
        $this->save('2030-01-04T14:00', $session);
        self::assertResponseStatusCodeSame(409);
    }

    public function testCancellationReleasesSeatsKeepsHistoryAndDoesNotNotifyTwice(): void
    {
        $this->login();
        $session = $this->save('2030-01-01T14:00')['id'];
        $booking = $this->book($session);
        $this->write('/' . $session . '/cancel');
        self::assertResponseIsSuccessful();
        self::assertSame('cancelled', $this->db->fetchOne('SELECT status FROM screening WHERE id = ?', [$session]));
        self::assertSame('cancelled', $this->db->fetchOne('SELECT status FROM reservation WHERE id = ?', [$booking]));
        self::assertSame('cinema', $this->db->fetchOne('SELECT cancellation_origin FROM reservation WHERE id = ?', [$booking]));
        self::assertNotNull($this->db->fetchOne('SELECT released_at FROM reserved_seat WHERE reservation_id = ?', [$booking]));
        self::assertCount(1, $this->sent);
        $this->write('/' . $session . '/cancel');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->sent);
        $this->save('2030-01-02T14:00', $session);
        self::assertResponseStatusCodeSame(409);
    }

    public function testScreeningCancellationPreservesEarlierClientCancellation(): void
    {
        $this->login();
        $session = $this->save('2030-01-02T14:00')['id'];
        $booking = $this->book($session);
        $this->db->update('reservation', [
            'status' => 'cancelled', 'cancellation_origin' => 'client',
            'cancelled_at' => '2030-01-01 10:00:00',
        ], ['id' => $booking]);
        $this->db->executeStatement("UPDATE reserved_seat SET released_at = '2030-01-01 10:00:00' WHERE reservation_id = ?", [$booking]);
        $this->write('/' . $session . '/cancel');
        self::assertResponseIsSuccessful();
        self::assertSame('client', $this->db->fetchOne('SELECT cancellation_origin FROM reservation WHERE id = ?', [$booking]));
        self::assertSame('2030-01-01 10:00:00', $this->db->fetchOne('SELECT cancelled_at FROM reservation WHERE id = ?', [$booking]));
        self::assertCount(0, $this->sent);
    }

    public function testFailedNotificationCanBeRetriedWithoutRepeatingCancellation(): void
    {
        $this->login();
        $session = $this->save('2030-01-02T14:00')['id'];
        $this->book($session);
        $this->mailFails = true;
        $data = $this->write('/' . $session . '/cancel');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $data['pendingEmails']);
        $this->mailFails = false;
        $data = $this->write('/' . $session . '/retry-emails');
        self::assertSame(1, $data['pendingEmails']);
        $this->clock->sleep(61);
        $data = $this->write('/' . $session . '/retry-emails');
        self::assertSame(0, $data['pendingEmails']);
        self::assertCount(1, $this->sent);
        $this->write('/' . $session . '/retry-emails');
        self::assertCount(1, $this->sent);
    }
}
