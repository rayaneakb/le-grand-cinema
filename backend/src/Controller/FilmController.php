<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class FilmController
{
    #[Route('/api/films', name: 'api_films', methods: ['GET'])]
    public function index(Connection $connection): JsonResponse
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
            SELECT f.id, f.title, f.synopsis, f.duration_minutes, f.main_actors, f.poster_url,
                   s.id AS screening_id, s.starts_at, r.name AS room_name,
                   COALESCE(capacity.total, 0) AS capacity,
                   COALESCE(occupied.total, 0) AS occupied
            FROM film f
            LEFT JOIN screening s ON s.film_id = f.id
                AND s.status = :screening_status AND s.starts_at > :now
            LEFT JOIN room r ON r.id = s.room_id
            LEFT JOIN (SELECT room_id, COUNT(*) AS total FROM seat GROUP BY room_id) capacity
                ON capacity.room_id = r.id
            LEFT JOIN (
                SELECT rs.screening_id, COUNT(*) AS total
                FROM reserved_seat rs
                JOIN reservation booking ON booking.id = rs.reservation_id
                WHERE rs.released_at IS NULL AND booking.status = :reservation_status
                GROUP BY rs.screening_id
            ) occupied ON occupied.screening_id = s.id
            WHERE f.is_showing = TRUE
            ORDER BY f.title, f.id, s.starts_at, s.id
            SQL
            ,
            [
                'screening_status' => 'scheduled',
                'reservation_status' => 'confirmed',
                'now' => $now->format('Y-m-d H:i:s')
            ]
        );

        $films = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (!isset($films[$id])) {
                $films[$id] = [
                    'id' => $id,
                    'title' => $row['title'],
                    'synopsis' => $row['synopsis'],
                    'durationMinutes' => (int) $row['duration_minutes'],
                    'mainActors' => $row['main_actors'],
                    'posterUrl' => $row['poster_url'],
                    'screenings' => []
                ];
            }
            if ($row['screening_id'] !== null) {
                $startsAt = new \DateTimeImmutable($row['starts_at'], new \DateTimeZone('UTC'));
                $availableSeats = max(0, (int) $row['capacity'] - (int) $row['occupied']);
                $films[$id]['screenings'][] = [
                    'id' => (int) $row['screening_id'],
                    'startsAt' => $startsAt->format(\DateTimeInterface::ATOM),
                    'room' => $row['room_name'],
                    'availableSeats' => $availableSeats,
                    'full' => $availableSeats === 0
                ];
            }
        }

        $response = new JsonResponse([
            'films' => array_values($films),
            'generatedAt' => $now->format(\DateTimeInterface::ATOM)
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
