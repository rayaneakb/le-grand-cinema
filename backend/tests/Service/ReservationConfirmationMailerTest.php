<?php

namespace App\Tests\Service;

use App\Service\ReservationAccess;
use App\Service\ReservationConfirmationMailer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class ReservationConfirmationMailerTest extends TestCase
{
    public function testCancelledReservationDoesNotInviteTheClientToPayOrCancelAgain(): void
    {
        $text = $this->message('cancelled');
        self::assertStringContainsString('Bonjour Camille,', $text);
        self::assertStringContainsString('Votre réservation est annulée.', $text);
        self::assertStringContainsString('Aucun montant n’est à régler', $text);
        self::assertStringContainsString('Consulter votre réservation annulée', $text);
        self::assertStringNotContainsString('Total à régler au cinéma', $text);
        self::assertStringNotContainsString('Merci d’arriver', $text);
        self::assertStringNotContainsString('Consulter ou annuler', $text);
    }

    public function testConfirmedReservationKeepsItsPaymentAndArrivalInstructions(): void
    {
        $text = $this->message('confirmed');
        self::assertStringContainsString('Votre réservation est confirmée.', $text);
        self::assertStringContainsString('Total à régler au cinéma : 20,00 €', $text);
        self::assertStringContainsString('Merci d’arriver environ 20 minutes', $text);
        self::assertStringContainsString('Consulter ou annuler votre réservation', $text);
        self::assertStringNotContainsString('Aucun montant n’est à régler', $text);
    }

    private function message(string $status): string
    {
        $message = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willReturnCallback(
            function (Email $email) use (&$message): void {
                $message = $email;
            }
        );
        $access = $this->createStub(ReservationAccess::class);
        $access->method('createUrl')->willReturn('https://cinema.example/reservations/demo');
        $service = new ReservationConfirmationMailer(
            $this->createStub(Connection::class), $mailer, $access,
            'Le Grand Cinéma <cinema@example.test>'
        );
        $service->sendPersonalLink([
            'status' => $status, 'reference' => 'DEMO123',
            'startsAt' => '2026-11-10T19:00:00+00:00',
            'totalAmountCents' => 2000, 'seats' => ['A1', 'A2'],
            'email' => 'camille@example.test', 'firstName' => 'Camille',
            'lastName' => 'Martin', 'filmTitle' => 'Film de démonstration',
            'room' => 'Salle 1',
        ]);

        return $message->getTextBody();
    }
}
