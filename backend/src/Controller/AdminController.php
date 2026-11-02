<?php

namespace App\Controller;

use App\Service\ReservationService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin')]
#[IsGranted('ROLE_EMPLOYEE')]
class AdminController extends AbstractController
{
    #[Route('/settings', name: 'api_admin_settings', methods: ['GET'])]
    public function settings(Connection $connection): JsonResponse
    {
        return $this->respond([
            'ticketPriceCents' => (int) $connection->fetchOne('SELECT ticket_price_cents FROM cinema_settings WHERE id = 1'),
            'canChangePrice' => $this->isGranted('ROLE_DIRECTOR'),
        ]);
    }

    #[Route('/settings/tariff', name: 'api_admin_tariff', methods: ['POST'])]
    #[IsGranted('ROLE_DIRECTOR')]
    public function tariff(Request $request, Connection $connection, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        if (!$csrf->isTokenValid(new CsrfToken('api', $request->headers->get('X-CSRF-Token', '')))) {
            return $this->respond(['message' => 'Votre formulaire a expiré. Réessayez.'], 403);
        }
        if ($request->getContentTypeFormat() !== 'json') {
            return $this->respond(['message' => 'Le format JSON est attendu.'], 415);
        }
        try {
            $data = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->respond(['message' => 'Le contenu JSON est invalide.'], 400);
        }
        if (!$data instanceof \stdClass || array_keys((array) $data) !== ['price'] || !is_string($data->price)) {
            return $this->respond(['message' => 'Saisissez un montant supérieur à 0 avec au maximum deux décimales.'], 422);
        }
        $price = trim($data->price);
        if (!preg_match('/^([0-9]{1,8})(?:[.,]([0-9]{1,2}))?$/D', $price, $parts)) {
            return $this->respond(['message' => 'Saisissez un montant supérieur à 0 avec au maximum deux décimales.'], 422);
        }
        // Les euros saisis sont convertis en centimes sans calcul à virgule flottante.
        $cents = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($cents <= 0 || $cents > 2147483647) {
            return $this->respond(['message' => 'Ce montant est nul ou trop élevé.'], 422);
        }
        $connection->executeStatement(
            'INSERT INTO cinema_settings (id, ticket_price_cents) VALUES (1, :price) ON CONFLICT (id) DO UPDATE SET ticket_price_cents = EXCLUDED.ticket_price_cents',
            ['price' => $cents]
        );

        return $this->respond(['ticketPriceCents' => $cents, 'message' => 'Le tarif est modifié pour les nouvelles réservations.']);
    }

    #[Route('/reservations', name: 'api_admin_reservations', methods: ['GET'])]
    public function reservations(Request $request, Connection $connection, ReservationService $reservations): JsonResponse
    {
        $query = trim($request->query->get('q', ''));
        $page = $request->query->get('page', '1');
        if ($query === '' || mb_strlen($query) > 180 || !preg_match('/^[1-9][0-9]{0,5}$/D', $page)) {
            return $this->respond(['message' => 'Saisissez un numéro de réservation ou une adresse mail.'], 422);
        }
        $rows = $connection->fetchAllAssociative(
            'SELECT id, status FROM reservation WHERE reference = :reference OR email = :email ORDER BY created_at DESC, id DESC LIMIT 21 OFFSET :offset',
            ['reference' => strtoupper($query), 'email' => strtolower($query), 'offset' => ((int) $page - 1) * 20],
            ['offset' => \Doctrine\DBAL\ParameterType::INTEGER]
        );
        $bookings = array_map(static fn (array $row): array => [
            ...$reservations->summary((int) $row['id']), 'status' => $row['status'],
        ], array_slice($rows, 0, 20));

        return $this->respond(['reservations' => $bookings, 'hasMore' => count($rows) > 20, 'page' => (int) $page]);
    }

    #[Route('/statistics', name: 'api_admin_statistics', methods: ['GET'])]
    #[IsGranted('ROLE_DIRECTOR')]
    public function statistics(Request $request, Connection $connection): JsonResponse
    {
        $paris = new \DateTimeZone('Europe/Paris');
        $from = $request->query->get('from', '');
        $to = $request->query->get('to', '');
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $from) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $to)) {
            return $this->respond(['message' => 'Choisissez une période valide.'], 422);
        }
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $paris);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $paris);
        if (!$start || !$end || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to || $end < $start || $end->diff($start)->days > 366) {
            return $this->respond(['message' => 'Choisissez une période valide, limitée à un an.'], 422);
        }
        $utc = new \DateTimeZone('UTC');
        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
            SELECT s.id, s.starts_at, f.title, r.name AS room, COALESCE(c.capacity, 0) AS capacity,
                   COUNT(DISTINCT b.id) FILTER (WHERE b.status = 'confirmed') AS confirmed,
                   COUNT(DISTINCT b.id) FILTER (WHERE b.status = 'cancelled') AS cancelled,
                   COUNT(rs.id) FILTER (WHERE b.status = 'confirmed' AND rs.released_at IS NULL) AS booked_seats
            FROM screening s
            JOIN film f ON f.id = s.film_id
            JOIN room r ON r.id = s.room_id
            LEFT JOIN (SELECT room_id, COUNT(*) AS capacity FROM seat GROUP BY room_id) c ON c.room_id = r.id
            LEFT JOIN reservation b ON b.screening_id = s.id
            LEFT JOIN reserved_seat rs ON rs.reservation_id = b.id
            WHERE s.status = 'scheduled' AND s.starts_at >= :start AND s.starts_at < :end
            GROUP BY s.id, f.title, r.name, c.capacity
            ORDER BY s.starts_at, s.id
            SQL,
            ['start' => $start->setTimezone($utc)->format('Y-m-d H:i:s'), 'end' => $end->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')]
        );
        $screenings = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'filmTitle' => $row['title'], 'room' => $row['room'],
            'startsAt' => (new \DateTimeImmutable($row['starts_at'], new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'capacity' => (int) $row['capacity'], 'bookedSeats' => (int) $row['booked_seats'],
            'confirmedReservations' => (int) $row['confirmed'], 'cancelledReservations' => (int) $row['cancelled'],
            'occupancyRate' => (int) $row['capacity'] > 0 ? round(100 * (int) $row['booked_seats'] / (int) $row['capacity'], 1) : null,
        ], $rows);
        $capacity = array_sum(array_column($screenings, 'capacity'));
        $booked = array_sum(array_column($screenings, 'bookedSeats'));

        return $this->respond([
            'screenings' => $screenings,
            'totals' => [
                'confirmedReservations' => array_sum(array_column($screenings, 'confirmedReservations')),
                'cancelledReservations' => array_sum(array_column($screenings, 'cancelledReservations')),
                'bookedSeats' => $booked,
                'occupancyRate' => $capacity > 0 ? round(100 * $booked / $capacity, 1) : null,
            ],
        ]);
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }
}
