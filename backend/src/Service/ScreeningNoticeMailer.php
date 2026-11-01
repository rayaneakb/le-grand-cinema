<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class ScreeningNoticeMailer
{
    public function __construct(
        private Connection $connection,
        private MailerInterface $mailer,
        private ClockInterface $clock,
        #[Autowire('%env(MAILER_FROM)%')] private string $sender,
    ) {
    }

    public function queue(int $reservation, string $subject, string $message): void
    {
        $this->connection->insert('screening_notice', [
            'reservation_id' => $reservation,
            'subject' => $subject,
            'message' => $message,
            'created_at' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
    }

    public function pending(int $screening): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM screening_notice n JOIN reservation b ON b.id = n.reservation_id WHERE b.screening_id = ? AND n.sent_at IS NULL', [$screening]
        );
    }

    public function sendPending(int $screening): int
    {
        // Un lot court évite de bloquer une requête si Brevo est indisponible.
        $ids = $this->connection->fetchFirstColumn(
            'SELECT n.id FROM screening_notice n JOIN reservation b ON b.id = n.reservation_id WHERE b.screening_id = ? AND n.sent_at IS NULL ORDER BY n.id LIMIT 10', [$screening]
        );
        foreach ($ids as $id) {
            $sent = $this->connection->transactional(function () use ($id): bool {
                $row = $this->connection->fetchAssociative('SELECT n.*, b.email FROM screening_notice n JOIN reservation b ON b.id = n.reservation_id WHERE n.id = ? FOR UPDATE OF n', [$id]);
                if (!$row || $row['sent_at'] !== null) return true;
                $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
                if ($row['attempted_at'] && $now->getTimestamp() - (new \DateTimeImmutable($row['attempted_at'], new \DateTimeZone('UTC')))->getTimestamp() < 60) return false;
                $timestamp = $now->format('Y-m-d H:i:s');
                $this->connection->update('screening_notice', ['attempted_at' => $timestamp], ['id' => $id]);
                try {
                    $this->mailer->send((new Email())->from(Address::create($this->sender))->to($row['email'])->subject($row['subject'])->text($row['message']));
                } catch (TransportExceptionInterface) {
                    return false;
                }
                $this->connection->update('screening_notice', ['sent_at' => $timestamp], ['id' => $id]);
                return true;
            });
            if (!$sent) break;
        }
        return $this->pending($screening);
    }
}
