<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class AccountReservationsController
{
    #[Route('/api/account/reservations', name: 'api_account_reservations', methods: ['GET'])]
    public function list(Request $request, Connection $connection, #[CurrentUser] ?User $user): JsonResponse
    {
        if ($user === null) {
            return $this->respond(['message' => 'Connectez-vous pour retrouver vos réservations.'], 401);
        }
        if ($user->getEmailVerifiedAt() === null) {
            return $this->respond(['message' => 'Vérifiez votre adresse mail pour retrouver vos réservations.'], 403);
        }
        $page = $request->query->get('page', '1');
        if (!is_string($page) || !preg_match('/^[1-9][0-9]{0,5}$/D', $page)) {
            return $this->respond(['message' => 'Le numéro de page est invalide.'], 422);
        }
        $page = (int) $page;
        $offset = ($page - 1) * 20;
        // L’adresse vient de la session vérifiée, jamais d’un paramètre du navigateur.
        // Les réservations sans compte à cette même adresse font partie de l’historique.
        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
            SELECT b.id, b.reference, b.status, b.cancellation_origin, b.total_amount_cents, s.starts_at,
                   f.title, r.name AS room,
                   COALESCE((SELECT string_agg(seat.row_label || seat.number::text, ', ' ORDER BY seat.row_label, seat.number)
                             FROM reserved_seat rs JOIN seat ON seat.id = rs.seat_id
                             WHERE rs.reservation_id = b.id), '') AS seats
            FROM reservation b
            JOIN screening s ON s.id = b.screening_id
            JOIN film f ON f.id = s.film_id
            JOIN room r ON r.id = s.room_id
            WHERE b.user_id = :owner OR (b.user_id IS NULL AND b.email = :email)
            ORDER BY s.starts_at DESC, b.id DESC
            LIMIT 21 OFFSET :offset
            SQL,
            ['owner' => $user->getId(), 'email' => $user->getEmail(), 'offset' => $offset],
            ['offset' => \Doctrine\DBAL\ParameterType::INTEGER]
        );
        $hasMore = count($rows) > 20;
        $bookings = array_map(static fn (array $row): array => [
            'reference' => $row['reference'],
            'status' => $row['status'],
            'cancellationOrigin' => $row['cancellation_origin'],
            'filmTitle' => $row['title'],
            'room' => $row['room'],
            'startsAt' => (new \DateTimeImmutable($row['starts_at'], new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'seats' => $row['seats'] === '' ? [] : explode(', ', $row['seats']),
            'totalAmountCents' => (int) $row['total_amount_cents'],
        ], array_slice($rows, 0, 20));

        return $this->respond(['reservations' => $bookings, 'page' => $page, 'hasMore' => $hasMore]);
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }
}
