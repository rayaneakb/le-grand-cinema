<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FilmAdministration
{
    public function __construct(
        private Connection $connection,
        private CloudinaryPoster $posters,
        private ClockInterface $clock,
    ) {
    }

    public function all(): array
    {
        return array_map($this->summary(...), $this->connection->fetchAllAssociative('SELECT * FROM film ORDER BY title, id'));
    }

    public function save(?int $id, array $data, ?UploadedFile $poster): array
    {
        if (array_diff(array_keys($data), ['title', 'synopsis', 'durationMinutes', 'mainActors', 'isShowing'])) {
            throw new \InvalidArgumentException('Le formulaire contient un champ inconnu.');
        }
        foreach (['title' => 255, 'synopsis' => 20000, 'mainActors' => 5000] as $field => $max) {
            if (!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === '' || mb_strlen(trim($data[$field])) > $max) {
                throw new \InvalidArgumentException('Complétez le titre, le synopsis et les acteurs principaux en respectant les longueurs indiquées.');
            }
            $data[$field] = trim($data[$field]);
        }
        $duration = filter_var($data['durationMinutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        if ($duration === false || !in_array($data['isShowing'] ?? null, ['0', '1'], true)) {
            throw new \InvalidArgumentException('La durée doit être un entier supérieur à 0. Précisez aussi si le film est à l’affiche.');
        }
        if ($id === null && !$poster) throw new \InvalidArgumentException('Choisissez une affiche pour ce film.');
        if ($id !== null && !$this->connection->fetchOne('SELECT id FROM film WHERE id = ?', [$id])) {
            throw new \OutOfBoundsException('Ce film est introuvable.');
        }
        if ($poster) $this->posters->validate($poster);
        $uploaded = $poster ? $this->posters->upload($poster) : null;
        $oldPoster = null;
        try {
            $saved = $this->connection->transactional(function () use ($id, $data, $duration, $uploaded, &$oldPoster): array {
                // Ce verrou est partagé avec la création et la modification des séances.
                $this->connection->executeQuery('SELECT pg_advisory_xact_lock(7812601)');
                $old = $id !== null ? $this->connection->fetchAssociative('SELECT * FROM film WHERE id = ? FOR UPDATE', [$id]) : null;
                if ($id !== null && !$old) throw new \OutOfBoundsException('Ce film est introuvable.');
                if ($old && (int) $old['duration_minutes'] !== $duration) $this->adjustScreenings($id, $duration);
                $values = [
                    'title' => $data['title'], 'synopsis' => $data['synopsis'], 'duration_minutes' => $duration,
                    'main_actors' => $data['mainActors'], 'is_showing' => $data['isShowing'] === '1' ? 'true' : 'false',
                    'poster_url' => $uploaded['url'] ?? $old['poster_url'],
                    'poster_public_id' => $uploaded['publicId'] ?? $old['poster_public_id'],
                ];
                if ($id === null) {
                    $id = (int) $this->connection->fetchOne(
                        'INSERT INTO film (title, synopsis, duration_minutes, main_actors, is_showing, poster_url, poster_public_id) VALUES (:title, :synopsis, :duration_minutes, :main_actors, :is_showing, :poster_url, :poster_public_id) RETURNING id', $values
                    );
                } else {
                    $this->connection->update('film', $values, ['id' => $id]);
                    if ($uploaded) $oldPoster = $old['poster_public_id'];
                }
                return $this->summary(['id' => $id, ...$values]);
            });
        } catch (\Throwable $failure) {
            if ($uploaded) $this->posters->remove($uploaded['publicId']);
            throw $failure;
        }
        $cleanup = $oldPoster === null || $this->posters->remove($oldPoster);
        return ['film' => $saved, 'message' => $cleanup ? 'Le film est enregistré.' : 'Le film est enregistré. L’ancienne affiche reste à supprimer dans Cloudinary.'];
    }

    private function adjustScreenings(int $film, int $duration): void
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $sessions = $this->connection->fetchAllAssociative("SELECT * FROM screening WHERE film_id = ? AND status = 'scheduled' AND starts_at > ? ORDER BY id FOR UPDATE", [$film, $now]);
        foreach ($sessions as $session) {
            $end = (new \DateTimeImmutable($session['starts_at'], new \DateTimeZone('UTC')))->modify('+' . $duration . ' minutes');
            $this->connection->update('screening', ['ends_at' => $end->format('Y-m-d H:i:s')], ['id' => $session['id']]);
        }
        foreach ($sessions as $session) {
            $conflict = $this->connection->fetchOne(
                <<<'SQL'
                SELECT other.id FROM screening current
                JOIN screening other ON other.room_id = current.room_id AND other.id <> current.id
                WHERE current.id = :id AND other.status = 'scheduled'
                  AND other.starts_at < current.ends_at + INTERVAL '1 hour'
                  AND other.ends_at + INTERVAL '1 hour' > current.starts_at
                LIMIT 1
                SQL, ['id' => $session['id']]
            );
            if ($conflict) throw new \DomainException('Cette durée ne laisse plus une heure entre deux séances dans la même salle. Le film reste inchangé.');
        }
    }

    private function summary(array $row): array
    {
        return [
            'id' => (int) $row['id'], 'title' => $row['title'], 'synopsis' => $row['synopsis'],
            'durationMinutes' => (int) $row['duration_minutes'], 'mainActors' => $row['main_actors'],
            'posterUrl' => $row['poster_url'], 'isShowing' => in_array($row['is_showing'], [true, 'true', 't', 1, '1'], true),
        ];
    }
}
