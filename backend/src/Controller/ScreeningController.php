<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class ScreeningController
{
    #[Route('/api/screenings/{id}/seats', name: 'api_screening_seats', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET'])]
    public function seats(int $id, Connection $connection): JsonResponse
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $screening = $connection->fetchAssociative(
            <<<'SQL'
            SELECT s.id, s.starts_at, s.status, f.title, f.is_showing,
                   r.id AS room_id, r.name AS room_name
            FROM screening s
            JOIN film f ON f.id = s.film_id
            JOIN room r ON r.id = s.room_id
            WHERE s.id = :id
            SQL,
            ['id' => $id]
        );

        if (!$screening || !$screening['is_showing']) {
            return $this->respond(['message' => 'Cette séance est introuvable.'], 404);
        }

        $startsAt = new \DateTimeImmutable($screening['starts_at'], new \DateTimeZone('UTC'));
        if ($screening['status'] !== 'scheduled' || $startsAt <= $now) {
            return $this->respond(['message' => 'Cette séance n’est plus disponible à la réservation.'], 410);
        }

        $price = $connection->fetchOne('SELECT ticket_price_cents FROM cinema_settings WHERE id = 1');
        if ($price === false || (int) $price <= 0) {
            return $this->respond(['message' => 'Le tarif est indisponible. Veuillez réessayer.'], 503);
        }

        // Une occupation concerne une séance précise, pas toutes les séances de la salle.
        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
            SELECT seat.id, seat.row_label, seat.number,
                   EXISTS (
                       SELECT 1 FROM reserved_seat rs
                       WHERE rs.seat_id = seat.id AND rs.screening_id = :screening
                           AND rs.released_at IS NULL
                   ) AS occupied
            FROM seat
            WHERE seat.room_id = :room
            ORDER BY seat.row_label, seat.number, seat.id
            SQL,
            ['screening' => $id, 'room' => $screening['room_id']]
        );

        $seats = array_map(static fn (array $seat): array => [
            'id' => (int) $seat['id'],
            'row' => $seat['row_label'],
            'number' => (int) $seat['number'],
            'available' => !$seat['occupied'],
        ], $rows);

        return $this->respond([
            'screening' => [
                'id' => $id,
                'filmTitle' => $screening['title'],
                'startsAt' => $startsAt->format(\DateTimeInterface::ATOM),
                'room' => $screening['room_name'],
            ],
            'unitPriceCents' => (int) $price,
            'seats' => $seats,
        ]);
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
