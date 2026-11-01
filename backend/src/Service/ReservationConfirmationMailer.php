<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class ReservationConfirmationMailer
{
    public function __construct(
        private Connection $connection,
        private MailerInterface $mailer,
        private ReservationAccess $access,
        #[Autowire('%env(MAILER_FROM)%')] private string $sender,
    ) {
    }

    public function send(string $confirmationKey, array $booking): array
    {
        return $this->connection->transactional(function () use ($confirmationKey, $booking): array {
            // Ce verrou évite que deux nouvelles tentatives envoient le même mail en parallèle.
            $row = $this->connection->fetchAssociative(
                'SELECT id, status, confirmation_email_sent_at, confirmation_email_attempted_at FROM reservation WHERE confirmation_key = :key FOR UPDATE',
                ['key' => $confirmationKey]
            );
            if (!$row || $row['status'] !== 'confirmed') {
                return ['emailSent' => false, 'emailRetryAfter' => 0];
            }
            if ($row['confirmation_email_sent_at'] !== null) {
                return ['emailSent' => true, 'emailRetryAfter' => 0];
            }
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if ($row['confirmation_email_attempted_at'] !== null) {
                $lastAttempt = new \DateTimeImmutable($row['confirmation_email_attempted_at'], new \DateTimeZone('UTC'));
                $wait = 60 - ($now->getTimestamp() - $lastAttempt->getTimestamp());
                if ($wait > 0) {
                    return ['emailSent' => false, 'emailRetryAfter' => $wait];
                }
            }
            $this->connection->update('reservation', [
                'confirmation_email_attempted_at' => $now->format('Y-m-d H:i:s'),
            ], ['id' => $row['id']]);

            try {
                $this->mailer->send($this->message($booking));
            } catch (TransportExceptionInterface) {
                // La réservation a déjà été validée avant l’appel au service mail.
                return ['emailSent' => false, 'emailRetryAfter' => 60];
            }
            $this->connection->update('reservation', [
                'confirmation_email_sent_at' => $now->format('Y-m-d H:i:s'),
            ], ['id' => $row['id']]);

            return ['emailSent' => true, 'emailRetryAfter' => 0];
        });
    }

    private function message(array $booking): Email
    {
        $date = (new \DateTimeImmutable($booking['startsAt']))->setTimezone(new \DateTimeZone('Europe/Paris'));
        $total = number_format($booking['totalAmountCents'] / 100, 2, ',', ' ');
        $count = count($booking['seats']);

        return (new Email())
            ->from(Address::create($this->sender))
            ->replyTo('le.grand.cinema.contact@gmail.com')
            ->to($booking['email'])
            ->subject('Votre réservation au Grand Cinéma — ' . $booking['reference'])
            ->text(
                'Bonjour ' . $booking['firstName'] . ",\n\n"
                . "Votre réservation est confirmée.\n\n"
                . 'Numéro de réservation : ' . $booking['reference'] . "\n"
                . 'Film : ' . $booking['filmTitle'] . "\n"
                . 'Séance : ' . $date->format('d/m/Y à H:i') . " (heure de Paris)\n"
                . 'Salle : ' . $booking['room'] . "\n"
                . 'Nom : ' . $booking['firstName'] . ' ' . $booking['lastName'] . "\n"
                . 'Places : ' . $count . ' — ' . implode(', ', $booking['seats']) . "\n"
                . 'Total à régler au cinéma : ' . $total . " €\n\n"
                . "Le paiement s’effectue au cinéma. Présentez votre référence au guichet.\n"
                . "Merci d’arriver environ 20 minutes avant le début de la séance.\n\n"
                . "Consulter ou annuler votre réservation (jusqu’à deux heures avant la séance) :\n"
                . $this->access->createUrl($booking) . "\n\n"
                . "Ce lien personnel reste valable 30 jours après la séance. Ne le partagez pas.\n\n"
                . "Le Grand Cinéma"
            );
    }
}
