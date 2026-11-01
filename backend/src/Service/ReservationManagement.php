<?php

namespace App\Service;

use App\Entity\User;
use App\Exception\ReservationProblem;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;

class ReservationManagement
{
    public function __construct(
        private Connection $connection,
        private ReservationAccess $access,
        private ReservationService $reservations,
        private ClockInterface $clock,
    ) {
    }

    public function consult(string $reference, string $query, ?User $user): array
    {
        $booking = $this->authorizedBooking($reference, $query, $user);

        return $this->details($booking);
    }

    public function cancel(string $reference, string $query, ?User $user): array
    {
        return $this->connection->transactional(function () use ($reference, $query, $user): array {
            $booking = $this->authorizedBooking($reference, $query, $user, true);
            if ($booking['status'] === 'cancelled') {
                return ['message' => 'Votre réservation a déjà été annulée.', 'reservation' => $this->details($booking)];
            }
            // L’heure est relue après l’obtention du verrou, au moment de confirmer l’annulation.
            $now = $this->clock->now();
            if ($now > $this->deadline($booking)) {
                throw new ReservationProblem('Le délai est dépassé. Vous ne pouvez plus annuler votre réservation.', 409);
            }
            $timestamp = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $this->connection->update('reservation', [
                'status' => 'cancelled', 'cancelled_at' => $timestamp,
            ], ['id' => $booking['id']]);
            $this->connection->executeStatement(
                'UPDATE reserved_seat SET released_at = :now WHERE reservation_id = :id AND released_at IS NULL',
                ['now' => $timestamp, 'id' => $booking['id']]
            );
            $booking['status'] = 'cancelled';

            return ['message' => 'Votre réservation est annulée. Les sièges ont été libérés.', 'reservation' => $this->details($booking)];
        });
    }

    private function authorizedBooking(string $reference, string $query, ?User $user, bool $lock = false): array
    {
        $signed = false;
        if ($query !== '') {
            try {
                $this->access->verify($reference, $query);
                $signed = true;
            } catch (SignedUriException) {
                throw new ReservationProblem('Ce lien est invalide ou a expiré. Utilisez le lien reçu par mail ou connectez-vous à votre compte.', 403);
            }
        }
        $verified = $user !== null && $user->getEmailVerifiedAt() !== null;
        if (!$signed && !$verified) {
            throw new ReservationProblem('Utilisez le lien reçu par mail ou connectez-vous à votre compte.', 403);
        }
        $booking = $this->connection->fetchAssociative(
            'SELECT b.id, b.status, b.email, s.starts_at FROM reservation b JOIN screening s ON s.id = b.screening_id WHERE b.reference = :reference'
                . ($lock ? ' FOR UPDATE OF b' : ''),
            ['reference' => $reference]
        );
        if (!$booking || (!$signed && $booking['email'] !== $user->getEmail())) {
            throw new ReservationProblem('Cette réservation est introuvable.', 404);
        }

        return $booking;
    }

    private function deadline(array $booking): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($booking['starts_at'], new \DateTimeZone('UTC')))->modify('-2 hours');
    }

    private function details(array $booking): array
    {
        return [
            ...$this->reservations->summary((int) $booking['id']),
            'status' => $booking['status'],
            'cancellationDeadline' => $this->deadline($booking)->format(\DateTimeInterface::ATOM),
            'canCancel' => $booking['status'] === 'confirmed' && $this->clock->now() <= $this->deadline($booking),
        ];
    }
}
