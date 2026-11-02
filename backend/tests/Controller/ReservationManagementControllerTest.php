<?php

namespace App\Tests\Controller;

use App\Entity\User;
use App\Service\ReservationAccess;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;

class ReservationManagementControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private MockClock $clock;
    private int $screening;
    private int $booking;
    private int $seat;
    private string $reference = 'ABCDEF0123456789';

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
            throw new \RuntimeException('Une base dédiée _test est obligatoire.');
        }
        $this->connection->beginTransaction();
        static::getContainer()->get('cache.rate_limiter')->clear();
        $this->clock = new MockClock('2030-01-01 17:59:00 UTC');
        static::getContainer()->set(ClockInterface::class, $this->clock);
        $room = $this->insert('room', ['name' => 'Salle annulation', 'type' => 'Standard']);
        $film = $this->insert('film', [
            'title' => 'Film annulation', 'synopsis' => 'Synopsis', 'duration_minutes' => 90,
            'main_actors' => 'Actrice', 'poster_url' => '/demo/posters/lumiere.svg',
            'poster_public_id' => 'demo/test', 'is_showing' => 'true',
        ]);
        $this->screening = $this->insert('screening', [
            'film_id' => $film, 'room_id' => $room, 'status' => 'scheduled',
            'starts_at' => '2030-01-01 20:00:00', 'ends_at' => '2030-01-01 21:30:00',
        ]);
        $this->seat = $this->insert('seat', ['room_id' => $room, 'row_label' => 'A', 'number' => 1]);
        $this->booking = $this->insert('reservation', [
            'reference' => $this->reference, 'screening_id' => $this->screening, 'status' => 'confirmed',
            'first_name' => 'Élodie', 'last_name' => 'Martin', 'email' => 'elodie@example.com',
            'unit_price_cents' => 1000, 'total_amount_cents' => 1000, 'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->insert('reserved_seat', ['reservation_id' => $this->booking, 'screening_id' => $this->screening, 'seat_id' => $this->seat]);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    private function query(?string $date = null): string
    {
        $url = static::getContainer()->get(ReservationAccess::class)->createUrl([
            'reference' => $this->reference, 'startsAt' => $date ?? '2030-01-01T20:00:00+00:00',
        ]);

        return '?' . parse_url($url, PHP_URL_QUERY);
    }

    private function cancel(string $query, bool $csrf = true): array
    {
        $this->client->request('GET', '/api/csrf');
        $token = json_decode($this->client->getResponse()->getContent(), true)['csrfToken'];
        $this->client->request('POST', '/api/reservations/' . $this->reference . '/cancel' . $query, server: $csrf ? ['HTTP_X_CSRF_TOKEN' => $token] : []);

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    public function testLostLinkIsSentOnlyToTheRecordedEmailWithoutDisclosingTheBooking(): void
    {
        $this->client->jsonRequest('POST', '/api/reservation-link', ['reference' => $this->reference, 'email' => 'autre@example.com']);
        self::assertResponseStatusCodeSame(202);
        self::assertEmailCount(0);
        $generic = $this->client->getResponse()->getContent();
        $this->client->jsonRequest('POST', '/api/reservation-link', ['reference' => strtolower($this->reference), 'email' => ' ELODIE@example.com ']);
        self::assertResponseStatusCodeSame(202);
        self::assertSame($generic, $this->client->getResponse()->getContent());
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        self::assertSame('elodie@example.com', $mail->getTo()[0]->getAddress());
        preg_match('~https?://[^\\s]+~', $mail->getTextBody(), $matches);
        $this->client->request('GET', '/api/reservations/' . $this->reference . '?' . parse_url($matches[0], PHP_URL_QUERY));
        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reservation WHERE reference = ?', [$this->reference]));
    }

    public function testLostLinkIsLimitedAndInvalidInputNeverSendsMail(): void
    {
        $this->client->jsonRequest('POST', '/api/reservation-link', ['reference' => $this->reference, 'email' => 'incorrect']);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/reservation-link', ['reference' => $this->reference, 'email' => 'elodie@example.com']);
        self::assertResponseStatusCodeSame(202);
        self::assertEmailCount(1);
        $this->client->jsonRequest('POST', '/api/reservation-link', ['reference' => $this->reference, 'email' => 'elodie@example.com']);
        self::assertResponseStatusCodeSame(429);
        self::assertEmailCount(0);
        self::assertNotNull($this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testExpiredReservationDoesNotReceiveANewLink(): void
    {
        $this->connection->update('screening', ['starts_at' => '2000-01-01 20:00:00'], ['id' => $this->screening]);
        $this->client->jsonRequest('POST', '/api/reservation-link', ['reference' => $this->reference, 'email' => 'elodie@example.com']);
        self::assertResponseStatusCodeSame(202);
        self::assertEmailCount(0);
    }

    public function testGuestNeedsTheValidPersonalLink(): void
    {
        $this->client->request('GET', '/api/reservations/' . $this->reference);
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString('elodie@example.com', $this->client->getResponse()->getContent());
        $query = $this->query();
        $this->client->request('GET', '/api/reservations/' . $this->reference . $query);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        $data = json_decode($this->client->getResponse()->getContent(), true)['reservation'];
        self::assertSame(['A1'], $data['seats']);
        self::assertTrue($data['canCancel']);
        self::assertNull($data['cancellationOrigin']);
        self::assertSame('confirmed', $data['status']);
        foreach (['/api/reservations/ABCDEF0123456788' . $query, '/api/reservations/' . $this->reference . $query . '&extra=1', '/api/reservations/' . $this->reference . $this->query('2000-01-01T20:00:00+00:00')] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function testOnlyTheVerifiedOwnerCanUseAnUnsignedUrl(): void
    {
        $user = (new User())->setFirstName('Élodie')->setLastName('Martin')
            ->setEmail('elodie@example.com')->setPassword('hash-test')
            ->setEmailVerifiedAt(new \DateTimeImmutable());
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $manager->persist($user);
        $manager->flush();
        $this->client->loginUser($user);
        $this->client->request('GET', '/api/reservations/' . $this->reference);
        self::assertResponseIsSuccessful();
        $this->connection->update('reservation', ['email' => 'autre@example.com'], ['id' => $this->booking]);
        $this->client->request('GET', '/api/reservations/' . $this->reference);
        self::assertResponseStatusCodeSame(404);
        $this->cancel('');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('confirmed', $this->connection->fetchOne('SELECT status FROM reservation WHERE id = ?', [$this->booking]));
    }

    public static function boundaries(): array
    {
        return [['17:59:00', 200], ['18:00:00', 200], ['18:00:01', 409], ['18:01:00', 409]];
    }

    #[DataProvider('boundaries')]
    public function testCancellationBoundaryIsCheckedOnTheServer(string $time, int $expected): void
    {
        $this->clock->modify('2030-01-01 ' . $time . ' UTC');
        $response = $this->cancel($this->query());
        self::assertResponseStatusCodeSame($expected);
        self::assertSame($expected === 200 ? 'cancelled' : 'confirmed', $this->connection->fetchOne('SELECT status FROM reservation WHERE id = ?', [$this->booking]));
        self::assertSame($expected === 200 ? 0 : 1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reserved_seat WHERE reservation_id = ? AND released_at IS NULL', [$this->booking]));
        self::assertSame($expected === 200 ? 'client' : null, $this->connection->fetchOne('SELECT cancellation_origin FROM reservation WHERE id = ?', [$this->booking]));
        if ($expected === 200) {
            self::assertFalse($response['reservation']['canCancel']);
            self::assertSame('client', $response['reservation']['cancellationOrigin']);
        }
    }

    public function testOpeningBeforeTheDeadlineDoesNotAllowLateConfirmation(): void
    {
        $query = $this->query();
        $this->client->request('GET', '/api/reservations/' . $this->reference . $query);
        self::assertResponseIsSuccessful();
        $this->clock->sleep(120);
        $this->cancel($query);
        self::assertResponseStatusCodeSame(409);
    }

    public function testGetAndMissingCsrfCannotCancelAndRepeatsKeepHistory(): void
    {
        $query = $this->query();
        $this->client->request('GET', '/api/reservations/' . $this->reference . $query);
        self::assertSame('confirmed', $this->connection->fetchOne('SELECT status FROM reservation WHERE id = ?', [$this->booking]));
        $this->cancel($query, false);
        self::assertResponseStatusCodeSame(403);
        $this->cancel('', true);
        self::assertResponseStatusCodeSame(403);
        $this->cancel($query);
        self::assertResponseIsSuccessful();
        $released = $this->connection->fetchOne('SELECT released_at FROM reserved_seat WHERE reservation_id = ?', [$this->booking]);
        self::assertNotNull($released);
        self::assertNotNull($this->connection->fetchOne('SELECT cancelled_at FROM reservation WHERE id = ?', [$this->booking]));
        // Une autre réservation peut reprendre le siège libéré.
        $this->connection->executeStatement("INSERT INTO reservation (reference, screening_id, first_name, last_name, email, status, unit_price_cents, total_amount_cents, created_at) SELECT 'FFFFFFFFFFFFFFFF', screening_id, first_name, last_name, email, 'confirmed', unit_price_cents, total_amount_cents, created_at FROM reservation WHERE id = ?", [$this->booking]);
        $other = $this->connection->fetchOne("SELECT id FROM reservation WHERE reference = 'FFFFFFFFFFFFFFFF'");
        $this->insert('reserved_seat', ['reservation_id' => $other, 'screening_id' => $this->screening, 'seat_id' => $this->seat]);
        $this->clock->sleep(600);
        $repeat = $this->cancel($query);
        self::assertResponseIsSuccessful();
        self::assertSame('Votre réservation a déjà été annulée.', $repeat['message']);
        self::assertSame($released, $this->connection->fetchOne('SELECT released_at FROM reserved_seat WHERE reservation_id = ?', [$this->booking]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reserved_seat WHERE seat_id = ? AND released_at IS NULL', [$this->seat]));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM reserved_seat WHERE reservation_id = ?', [$this->booking]));
    }

    public function testHistoryRequiresAVerifiedAccount(): void
    {
        $this->client->request('GET', '/api/account/reservations');
        self::assertResponseStatusCodeSame(401);
        $this->loginOwner(false);
        $this->client->request('GET', '/api/account/reservations');
        self::assertResponseStatusCodeSame(403);
    }

    public function testHistoryIncludesGuestBookingsButNeverAnotherCustomers(): void
    {
        $this->loginOwner();
        // La réservation existait avant le compte et n’a pas de user_id.
        self::assertNull($this->connection->fetchOne('SELECT user_id FROM reservation WHERE id = ?', [$this->booking]));
        $this->connection->executeStatement("INSERT INTO reservation (reference, screening_id, first_name, last_name, email, status, unit_price_cents, total_amount_cents, created_at) SELECT 'FFFFFFFFFFFFFFFF', screening_id, first_name, last_name, 'autre@example.com', status, unit_price_cents, total_amount_cents, created_at FROM reservation WHERE id = ?", [$this->booking]);
        $this->client->request('GET', '/api/account/reservations?email=autre@example.com');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $data['reservations']);
        self::assertSame($this->reference, $data['reservations'][0]['reference']);
        self::assertSame(['A1'], $data['reservations'][0]['seats']);
        self::assertFalse($data['hasMore']);
        $this->cancel('');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/account/reservations');
        $cancelled = json_decode($this->client->getResponse()->getContent(), true)['reservations'][0];
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('client', $cancelled['cancellationOrigin']);
        self::assertSame(['A1'], $cancelled['seats']);
    }

    public function testHistoryPaginationAndEmptyList(): void
    {
        $this->loginOwner();
        for ($i = 1; $i <= 20; ++$i) {
            $this->connection->executeStatement("INSERT INTO reservation (reference, screening_id, first_name, last_name, email, status, unit_price_cents, total_amount_cents, created_at) SELECT ?, screening_id, first_name, last_name, email, status, unit_price_cents, total_amount_cents, created_at FROM reservation WHERE id = ?", [sprintf('%016X', $i), $this->booking]);
        }
        $this->client->request('GET', '/api/account/reservations?page=1');
        $first = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(20, $first['reservations']);
        self::assertTrue($first['hasMore']);
        $this->client->request('GET', '/api/account/reservations?page=2');
        $second = json_decode($this->client->getResponse()->getContent(), true);
        self::assertCount(1, $second['reservations']);
        self::assertFalse($second['hasMore']);
        self::assertSame($this->reference, $second['reservations'][0]['reference']);
        $this->client->request('GET', '/api/account/reservations?page=0');
        self::assertResponseStatusCodeSame(422);
        $this->connection->executeStatement('UPDATE reservation SET email = ? WHERE email = ?', ['autre@example.com', 'elodie@example.com']);
        $this->client->request('GET', '/api/account/reservations');
        self::assertSame([], json_decode($this->client->getResponse()->getContent(), true)['reservations']);
    }

    public function testPdfIsDownloadedWithThePersonalLinkButNotWithTheReferenceAlone(): void
    {
        $path = '/api/reservations/' . $this->reference . '/pdf';
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(403);
        $query = $this->query();
        $this->client->request('GET', $path . $query);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertResponseHeaderSame('Content-Disposition', 'attachment; filename=reservation-' . $this->reference . '.pdf');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString('no-store', $this->client->getResponse()->headers->get('Cache-Control'));
        $pdf = $this->client->getResponse()->getContent();
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('%%EOF', $pdf);
        self::assertGreaterThan(1000, strlen($pdf));
        $this->client->request('GET', '/api/reservations/ABCDEF0123456788/pdf' . $query);
        self::assertResponseStatusCodeSame(403);
    }

    public function testPdfUsesTheSameVerifiedOwnerCheckAsTheDetails(): void
    {
        $this->loginOwner();
        $path = '/api/reservations/' . $this->reference . '/pdf';
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        $this->connection->update('reservation', ['email' => 'autre@example.com'], ['id' => $this->booking]);
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    public function testPdfIsRefusedAfterCancellationEvenIfTheLinkWasOpenedBefore(): void
    {
        $query = $this->query();
        $this->cancel($query);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/reservations/' . $this->reference . '/pdf' . $query);
        self::assertResponseStatusCodeSame(409);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertStringContainsString('annulée', json_decode($this->client->getResponse()->getContent(), true)['message']);
    }

    private function loginOwner(bool $verified = true): User
    {
        $user = (new User())->setFirstName('Élodie')->setLastName('Martin')
            ->setEmail('elodie@example.com')->setPassword('hash-test');
        if ($verified) {
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
        }
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        $manager->persist($user);
        $manager->flush();
        $this->client->loginUser($user);

        return $user;
    }

    private function insert(string $table, array $data): int
    {
        return (int) $this->connection->fetchOne('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($data)) . ') VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ') RETURNING id', array_values($data));
    }
}
