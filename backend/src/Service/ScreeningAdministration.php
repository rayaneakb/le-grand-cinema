<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

class ScreeningAdministration
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
        private ScreeningNoticeMailer $notices,
    ) {
    }

    public function list(string $date): array
    {
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)) throw new ScreeningProblem('Choisissez une date valide.');
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('Europe/Paris'));
        if (!$day || $day->format('Y-m-d') !== $date) throw new ScreeningProblem('Choisissez une date valide.');
        $utc = new \DateTimeZone('UTC');
        $sessions = $this->connection->fetchAllAssociative(
            <<<'SQL'
            SELECT s.*, f.title AS film_title, r.name AS room,
              (SELECT COUNT(*) FROM reservation b WHERE b.screening_id = s.id) AS reservation_count,
              (SELECT COUNT(*) FROM screening_notice n JOIN reservation b ON b.id = n.reservation_id WHERE b.screening_id = s.id AND n.sent_at IS NULL) AS pending_emails
            FROM screening s JOIN film f ON f.id = s.film_id JOIN room r ON r.id = s.room_id
            WHERE s.starts_at >= ? AND s.starts_at < ? ORDER BY s.starts_at, s.id
            SQL,
            [$day->setTimezone($utc)->format('Y-m-d H:i:s'), $day->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')]
        );
        return [
            'screenings' => array_map($this->summary(...), $sessions),
            'rooms' => array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['name']], $this->connection->fetchAllAssociative('SELECT id, name FROM room ORDER BY id')),
            'films' => array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'title' => $row['title'], 'durationMinutes' => (int) $row['duration_minutes']], $this->connection->fetchAllAssociative('SELECT id, title, duration_minutes FROM film WHERE is_showing = TRUE ORDER BY title, id')),
        ];
    }

    public function save(?int $id, array $data): array
    {
        if (array_diff(array_keys($data), ['filmId', 'roomId', 'startsAt']) || count($data) !== 3
            || !is_int($data['filmId'] ?? null) || !is_int($data['roomId'] ?? null)
            || !is_string($data['startsAt'] ?? null)) throw new ScreeningProblem('Choisissez un film, une salle et un horaire.');
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D', $data['startsAt'])) throw new ScreeningProblem('Choisissez un horaire valide.');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $data['startsAt'], new \DateTimeZone('Europe/Paris'));
        if (!$date || $date->format('Y-m-d\TH:i') !== $data['startsAt']) throw new ScreeningProblem('L’horaire est invalide. Les horaires sont exprimés en heure de Paris.');
        $start = $date->setTimezone(new \DateTimeZone('UTC'));
        $savedId = $this->connection->transactional(function () use ($id, $data, $start): int {
            $this->connection->executeQuery('SELECT pg_advisory_xact_lock(7812601)');
            $old = $id ? $this->connection->fetchAssociative('SELECT * FROM screening WHERE id = ? FOR UPDATE', [$id]) : null;
            if ($id && !$old) throw new ScreeningProblem('Cette séance est introuvable.', 404);
            $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
            if ($start <= $now) throw new ScreeningProblem('La séance doit commencer dans le futur.');
            if ($old && ($old['status'] !== 'scheduled' || new \DateTimeImmutable($old['starts_at'], new \DateTimeZone('UTC')) <= $now)) {
                throw new ScreeningProblem('Une séance passée ou annulée ne peut plus être modifiée.', 409);
            }
            $film = $this->connection->fetchAssociative('SELECT id, title, duration_minutes FROM film WHERE id = ? AND is_showing = TRUE', [$data['filmId']]);
            if (!$film || !$this->connection->fetchOne('SELECT id FROM room WHERE id = ?', [$data['roomId']])) throw new ScreeningProblem('Ce film ou cette salle n’est pas disponible.');
            $bookings = $id ? $this->connection->fetchAllAssociative('SELECT id, reference, first_name, status FROM reservation WHERE screening_id = ? ORDER BY id FOR UPDATE', [$id]) : [];
            $timestamp = $start->format('Y-m-d H:i:s');
            $timeChanged = $old && $old['starts_at'] !== $timestamp;
            if ($bookings && ((int) $old['film_id'] !== $data['filmId'] || (int) $old['room_id'] !== $data['roomId'])) {
                throw new ScreeningProblem('Cette séance a déjà des réservations. Pour changer le film ou la salle, annulez-la puis créez une nouvelle séance.', 409);
            }
            if ($bookings && $timeChanged && ($start < $now->modify('+24 hours') || new \DateTimeImmutable($old['starts_at'], new \DateTimeZone('UTC')) < $now->modify('+24 hours'))) {
                throw new ScreeningProblem('L’ancien et le nouvel horaire doivent être à au moins 24 heures du moment de la modification.', 409);
            }
            $end = $start->modify('+' . (int) $film['duration_minutes'] . ' minutes');
            $conflict = $this->connection->fetchOne(
                "SELECT id FROM screening WHERE room_id = :room AND status = 'scheduled' AND id <> :id AND starts_at < CAST(:end AS timestamp) + INTERVAL '1 hour' AND ends_at + INTERVAL '1 hour' > CAST(:start AS timestamp) LIMIT 1",
                ['room' => $data['roomId'], 'id' => $id ?? 0, 'start' => $timestamp, 'end' => $end->format('Y-m-d H:i:s')]
            );
            if ($conflict) throw new ScreeningProblem('Aucune disponibilité dans cette salle à cet horaire. Gardez une heure entre deux séances.', 409);
            $values = ['film_id' => $data['filmId'], 'room_id' => $data['roomId'], 'starts_at' => $timestamp, 'ends_at' => $end->format('Y-m-d H:i:s'), 'status' => 'scheduled'];
            if ($id) $this->connection->update('screening', $values, ['id' => $id]);
            else $id = (int) $this->connection->fetchOne('INSERT INTO screening (film_id, room_id, starts_at, ends_at, status) VALUES (:film_id, :room_id, :starts_at, :ends_at, :status) RETURNING id', $values);
            if ($timeChanged) {
                $previous = (new \DateTimeImmutable($old['starts_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i');
                foreach ($bookings as $booking) {
                    if ($booking['status'] !== 'confirmed') continue;
                    $this->notices->queue((int) $booking['id'], 'Changement d’horaire de votre séance',
                        'Bonjour ' . $booking['first_name'] . ",\n\nLa séance de « " . $film['title'] . ' » prévue le ' . $previous
                        . ' est déplacée au ' . $start->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i')
                        . " (heure de Paris).\nVotre réservation " . $booking['reference'] . " et vos sièges sont conservés.\nTéléchargez de nouveau votre confirmation depuis votre compte ou votre lien personnel.\n\nLe Grand Cinéma");
                }
            }
            return $id;
        });
        return ['id' => $savedId, 'message' => 'La séance est enregistrée.', 'pendingEmails' => $this->notices->sendPending($savedId)];
    }

    public function cancel(int $id): array
    {
        $this->connection->transactional(function () use ($id): void {
            $this->connection->executeQuery('SELECT pg_advisory_xact_lock(7812601)');
            $session = $this->connection->fetchAssociative('SELECT s.*, f.title FROM screening s JOIN film f ON f.id = s.film_id WHERE s.id = ? FOR UPDATE OF s', [$id]);
            if (!$session) throw new ScreeningProblem('Cette séance est introuvable.', 404);
            if ($session['status'] === 'cancelled') return;
            $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
            if (new \DateTimeImmutable($session['starts_at'], new \DateTimeZone('UTC')) <= $now) throw new ScreeningProblem('Cette séance a déjà commencé.', 409);
            $bookings = $this->connection->fetchAllAssociative('SELECT id, reference, first_name, status FROM reservation WHERE screening_id = ? ORDER BY id FOR UPDATE', [$id]);
            $timestamp = $now->format('Y-m-d H:i:s');
            $this->connection->update('screening', ['status' => 'cancelled'], ['id' => $id]);
            $this->connection->executeStatement("UPDATE reservation SET status = 'cancelled', cancelled_at = ?, cancellation_origin = 'cinema' WHERE screening_id = ? AND status = 'confirmed'", [$timestamp, $id]);
            $this->connection->executeStatement('UPDATE reserved_seat SET released_at = ? WHERE screening_id = ? AND released_at IS NULL', [$timestamp, $id]);
            foreach ($bookings as $booking) {
                if ($booking['status'] !== 'confirmed') continue;
                $this->notices->queue((int) $booking['id'], 'Annulation de votre séance au Grand Cinéma',
                    'Bonjour ' . $booking['first_name'] . ",\n\nLa séance de « " . $session['title'] . ' » du '
                    . (new \DateTimeImmutable($session['starts_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i')
                    . " (heure de Paris) est annulée par le cinéma.\nVotre réservation " . $booking['reference']
                    . " est annulée et vos sièges sont libérés. Aucun paiement en ligne n’a été effectué.\n\nLe Grand Cinéma");
            }
        });
        return ['id' => $id, 'message' => 'La séance et ses réservations sont annulées. Les sièges sont libérés.', 'pendingEmails' => $this->notices->sendPending($id)];
    }

    private function summary(array $row): array
    {
        $utc = new \DateTimeZone('UTC');
        return [
            'id' => (int) $row['id'], 'filmId' => (int) $row['film_id'], 'filmTitle' => $row['film_title'],
            'roomId' => (int) $row['room_id'], 'room' => $row['room'], 'status' => $row['status'],
            'startsAt' => (new \DateTimeImmutable($row['starts_at'], $utc))->format(\DateTimeInterface::ATOM),
            'endsAt' => (new \DateTimeImmutable($row['ends_at'], $utc))->format(\DateTimeInterface::ATOM),
            'reservationCount' => (int) $row['reservation_count'], 'pendingEmails' => (int) $row['pending_emails'],
            'canEdit' => $row['status'] === 'scheduled' && new \DateTimeImmutable($row['starts_at'], $utc) > $this->clock->now(),
        ];
    }
}
