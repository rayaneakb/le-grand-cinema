<?php

namespace App\Service;

use App\Entity\User;
use App\Exception\ReservationProblem;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

class ReservationService
{
    public function __construct(private Connection $connection)
    {
    }

    public function confirm(array $data, string $confirmationKey, ?User $user): array
    {
        $data['firstName'] = trim($data['firstName']);
        $data['lastName'] = trim($data['lastName']);
        $data['email'] = strtolower(trim($data['email']));
        sort($data['seatIds'], SORT_NUMERIC);
        $fingerprint = hash('sha256', json_encode([
            $data['screeningId'], $data['seatIds'], $data['firstName'], $data['lastName'],
            $data['email'], $data['expectedUnitPriceCents'],
        ], JSON_THROW_ON_ERROR));

        try {
            return $this->connection->transactional(function () use ($data, $confirmationKey, $fingerprint, $user): array {
                // Deux essais de la même demande sont traités l’un après l’autre.
                $this->connection->executeQuery(
                    'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
                    ['key' => $confirmationKey]
                );
                $existing = $this->connection->fetchAssociative(
                    'SELECT id, request_fingerprint, status FROM reservation WHERE confirmation_key = :key',
                    ['key' => $confirmationKey]
                );
                if ($existing) {
                    if (!hash_equals($existing['request_fingerprint'], $fingerprint)) {
                        throw new ReservationProblem('La demande a changé. Vérifiez votre récapitulatif avant de réessayer.', 409);
                    }
                    if ($existing['status'] !== 'confirmed') {
                        throw new ReservationProblem('Cette réservation a été annulée. Commencez une nouvelle réservation.', 409);
                    }

                    return ['reservation' => $this->summary((int) $existing['id']), 'replayed' => true];
                }

                // Les confirmations d’une même séance partagent ce verrou jusqu’à la fin de la transaction.
                $screening = $this->connection->fetchAssociative(
                    <<<'SQL'
                    SELECT s.id, s.room_id, s.starts_at, s.status, f.is_showing
                    FROM screening s JOIN film f ON f.id = s.film_id
                    WHERE s.id = :id FOR UPDATE OF s
                    SQL,
                    ['id' => $data['screeningId']]
                );
                if (!$screening) {
                    throw new ReservationProblem('Cette séance est introuvable.', 404);
                }
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $startsAt = new \DateTimeImmutable($screening['starts_at'], new \DateTimeZone('UTC'));
                if (!$screening['is_showing'] || $screening['status'] !== 'scheduled' || $startsAt <= $now) {
                    throw new ReservationProblem('Cette séance n’est plus disponible à la réservation.', 410);
                }
                $seats = $this->connection->fetchAllAssociative(
                    <<<'SQL'
                    SELECT seat.id, seat.row_label, seat.number,
                           EXISTS (SELECT 1 FROM reserved_seat rs
                                   WHERE rs.seat_id = seat.id AND rs.screening_id = :screening
                                   AND rs.released_at IS NULL) AS occupied
                    FROM seat WHERE seat.room_id = :room AND seat.id IN (:ids)
                    ORDER BY seat.row_label, seat.number
                    SQL,
                    ['screening' => $data['screeningId'], 'room' => $screening['room_id'], 'ids' => $data['seatIds']],
                    ['ids' => ArrayParameterType::INTEGER]
                );
                if (count($seats) !== count($data['seatIds'])) {
                    throw new ReservationProblem('Un siège sélectionné n’appartient pas à cette salle.', 422);
                }
                $unavailable = array_values(array_filter($seats, static fn (array $seat): bool => (bool) $seat['occupied']));
                if ($unavailable !== []) {
                    throw new ReservationProblem('Certains sièges viennent d’être réservés. Vérifiez votre sélection.', 409, [
                        'status' => 'seats_unavailable',
                        'unavailableSeatIds' => array_map(static fn (array $seat): int => (int) $seat['id'], $unavailable),
                    ]);
                }
                $price = $this->connection->fetchOne('SELECT ticket_price_cents FROM cinema_settings WHERE id = 1 FOR SHARE');
                if ($price === false || (int) $price <= 0) {
                    throw new ReservationProblem('Le tarif est indisponible. Veuillez réessayer.', 503);
                }
                $price = (int) $price;
                if ($price > intdiv(2147483647, count($seats))) {
                    throw new ReservationProblem('Le montant de cette réservation dépasse la limite acceptée.', 422);
                }
                if ($price !== $data['expectedUnitPriceCents']) {
                    throw new ReservationProblem('Le tarif a changé. Vérifiez le nouveau total avant de confirmer.', 409, [
                        'status' => 'price_changed', 'unitPriceCents' => $price,
                    ]);
                }
                $owner = $user !== null && $user->getEmailVerifiedAt() !== null && $user->getEmail() === $data['email']
                    ? $user->getId() : null;
                $id = (int) $this->connection->fetchOne(
                    <<<'SQL'
                    INSERT INTO reservation
                        (reference, screening_id, user_id, first_name, last_name, email, status,
                         unit_price_cents, total_amount_cents, created_at, confirmation_key, request_fingerprint)
                    VALUES (:reference, :screening, :owner, :first, :last, :email, 'confirmed',
                            :price, :total, :created, :key, :fingerprint)
                    RETURNING id
                    SQL,
                    [
                        'reference' => strtoupper(bin2hex(random_bytes(8))), 'screening' => $data['screeningId'],
                        'owner' => $owner, 'first' => $data['firstName'], 'last' => $data['lastName'],
                        'email' => $data['email'], 'price' => $price, 'total' => $price * count($seats),
                        'created' => $now->format('Y-m-d H:i:s'), 'key' => $confirmationKey, 'fingerprint' => $fingerprint,
                    ]
                );
                foreach ($seats as $seat) {
                    $this->connection->insert('reserved_seat', [
                        'reservation_id' => $id, 'screening_id' => $data['screeningId'], 'seat_id' => $seat['id'],
                    ]);
                }

                return ['reservation' => $this->summary($id), 'replayed' => false];
            });
        } catch (UniqueConstraintViolationException) {
            // L’index unique protège aussi les écritures qui ne passeraient pas par le verrou de séance.
            throw new ReservationProblem('Un siège n’est plus disponible. Vérifiez votre sélection.', 409, [
                'status' => 'seats_unavailable', 'unavailableSeatIds' => [],
            ]);
        }
    }

    public function summary(int $id): array
    {
        $booking = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT b.reference, b.first_name, b.last_name, b.email, b.unit_price_cents,
                   b.total_amount_cents, b.cancellation_origin, s.starts_at, r.name AS room, f.title
            FROM reservation b
            JOIN screening s ON s.id = b.screening_id
            JOIN room r ON r.id = s.room_id
            JOIN film f ON f.id = s.film_id
            WHERE b.id = :id
            SQL,
            ['id' => $id]
        );
        $seats = $this->connection->fetchAllAssociative(
            'SELECT seat.row_label, seat.number FROM reserved_seat rs JOIN seat ON seat.id = rs.seat_id WHERE rs.reservation_id = :id ORDER BY seat.row_label, seat.number',
            ['id' => $id]
        );

        return [
            'cancellationOrigin' => $booking['cancellation_origin'],
            'reference' => $booking['reference'], 'firstName' => $booking['first_name'],
            'lastName' => $booking['last_name'], 'email' => $booking['email'],
            'filmTitle' => $booking['title'], 'room' => $booking['room'],
            'startsAt' => (new \DateTimeImmutable($booking['starts_at'], new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'unitPriceCents' => (int) $booking['unit_price_cents'], 'totalAmountCents' => (int) $booking['total_amount_cents'],
            'seats' => array_map(static fn (array $seat): string => $seat['row_label'] . $seat['number'], $seats),
        ];
    }
}
