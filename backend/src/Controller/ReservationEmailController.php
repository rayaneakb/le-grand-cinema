<?php

namespace App\Controller;

use App\Service\ReservationConfirmationMailer;
use App\Service\ReservationService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[IsGranted('ROLE_EMPLOYEE')]
class ReservationEmailController
{
    #[Route('/api/admin/reservations/{reference}/email', name: 'api_admin_reservation_email', requirements: ['reference' => '[A-F0-9]{16}'], methods: ['POST'])]
    public function correct(
        Request $request,
        string $reference,
        Connection $connection,
        ReservationService $reservations,
        ReservationConfirmationMailer $mailer,
        CsrfTokenManagerInterface $csrf,
        ValidatorInterface $validator,
    ): JsonResponse {
        if (!$csrf->isTokenValid(new CsrfToken('api', $request->headers->get('X-CSRF-Token', '')))) {
            return $this->respond('Votre formulaire a expiré. Réessayez.', 403);
        }
        try {
            $data = $request->getContentTypeFormat() === 'json'
                ? json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            $data = null;
        }
        if (!$data instanceof \stdClass || !is_string($data->email ?? null)
            || !is_string($data->expectedEmail ?? null) || !is_string($data->firstName ?? null)
            || !is_string($data->lastName ?? null) || ($data->requesterVerified ?? false) !== true) {
            return $this->respond('Vérifiez le nom et le prénom du demandeur avant de corriger son adresse mail.', 422);
        }
        $email = strtolower(trim($data->email));
        if (count($validator->validate($email, [new Assert\NotBlank(), new Assert\Length(max: 180), new Assert\Email()])) !== 0) {
            return $this->respond('Saisissez une adresse mail valide.', 422);
        }
        $connection->beginTransaction();
        try {
            $row = $connection->fetchAssociative('SELECT * FROM reservation WHERE reference = :reference FOR UPDATE', ['reference' => $reference]);
            if (!$row) {
                $connection->rollBack();
                return $this->respond('Cette réservation est introuvable.', 404);
            }
            if ($row['status'] !== 'confirmed') {
                $connection->rollBack();
                return $this->respond('Cette réservation est annulée. Son adresse mail ne peut plus être corrigée.', 409);
            }
            if ($row['email'] !== $data->expectedEmail) {
                $connection->rollBack();
                return $this->respond('Cette réservation a changé. Relancez la recherche avant de réessayer.', 409);
            }
            if (mb_strtolower(trim($data->firstName)) !== mb_strtolower($row['first_name'])
                || mb_strtolower(trim($data->lastName)) !== mb_strtolower($row['last_name'])) {
                $connection->rollBack();
                return $this->respond('Le nom et le prénom ne correspondent pas à cette réservation.', 422);
            }
            if ($email === $row['email']) {
                $connection->rollBack();
                return $this->respond('Cette adresse est déjà enregistrée. Utilisez le renvoi du lien si nécessaire.', 422);
            }
            $key = bin2hex(random_bytes(16));
            // Le compte, les sièges et le prix ne sont pas modifiés. Les anciens liens deviennent inutilisables.
            $connection->update('reservation', [
                'email' => $email, 'access_version' => bin2hex(random_bytes(16)), 'confirmation_key' => $key,
                'confirmation_email_sent_at' => null, 'confirmation_email_attempted_at' => null,
            ], ['id' => $row['id']]);
            $connection->commit();
        } catch (\Throwable $error) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $error;
        }
        $booking = $reservations->summary((int) $row['id']);
        $delivery = $mailer->send($key, $booking);

        return new JsonResponse([
            'message' => $delivery['emailSent']
                ? 'L’adresse mail est corrigée et la confirmation a été envoyée.'
                : 'L’adresse mail est corrigée. L’envoi a échoué. Le client peut demander un nouveau lien.',
            'reservation' => [...$booking, 'status' => $row['status']],
            'emailSent' => $delivery['emailSent'],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    private function respond(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status, ['Cache-Control' => 'no-store']);
    }
}
