<?php

namespace App\Controller;

use App\Service\ReservationConfirmationMailer;
use App\Service\ReservationService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ReservationLinkController
{
    #[Route('/api/reservation-link', name: 'api_reservation_link', methods: ['POST'])]
    public function send(
        Request $request,
        Connection $connection,
        ReservationService $reservations,
        ReservationConfirmationMailer $mailer,
        ValidatorInterface $validator,
        LoggerInterface $logger,
        #[Autowire(service: 'limiter.reservation_link')] RateLimiterFactoryInterface $limiter,
        #[Autowire(service: 'limiter.reservation_link_ip')] RateLimiterFactoryInterface $ipLimiter,
    ): JsonResponse {
        try {
            $data = $request->getContentTypeFormat() === 'json'
                ? json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR) : null;
        } catch (\JsonException) {
            $data = null;
        }
        if (!$data instanceof \stdClass || !is_string($data->email ?? null) || !is_string($data->reference ?? null)) {
            return $this->respond('Saisissez votre adresse mail et votre numéro de réservation.', 400);
        }
        $email = strtolower(trim($data->email));
        $reference = strtoupper(trim($data->reference));
        if (!preg_match('/^[A-F0-9]{16}$/D', $reference)
            || count($validator->validate($email, [new Assert\NotBlank(), new Assert\Length(max: 180), new Assert\Email()])) !== 0) {
            return $this->respond('Vérifiez votre adresse mail et votre numéro de réservation.', 422);
        }
        $addressLimit = $limiter->create(hash('sha256', $email))->consume();
        $networkLimit = $ipLimiter->create(hash('sha256', $request->getClientIp() ?? 'unknown'))->consume();
        if (!$addressLimit->isAccepted() || !$networkLimit->isAccepted()) {
            $response = $this->respond('Trop de demandes. Patientez avant de réessayer.', 429);
            $response->headers->set('Retry-After', (string) max(1,
                $addressLimit->getRetryAfter()->getTimestamp() - time(),
                $networkLimit->getRetryAfter()->getTimestamp() - time()
            ));

            return $response;
        }
        $row = $connection->fetchAssociative(
            'SELECT b.id, b.status FROM reservation b JOIN screening s ON s.id = b.screening_id WHERE b.reference = :reference AND b.email = :email AND s.starts_at > :cutoff',
            ['reference' => $reference, 'email' => $email, 'cutoff' => gmdate('Y-m-d H:i:s', time() - 30 * 86400)]
        );
        if ($row) {
            try {
                $mailer->sendPersonalLink([...$reservations->summary((int) $row['id']), 'status' => $row['status']]);
            } catch (TransportExceptionInterface) {
                $logger->error('Échec du renvoi du lien de réservation.');
            }
        }

        return $this->respond('Si ces informations correspondent à une réservation, vous recevrez son lien personnel par mail. Pensez à regarder vos spams.', 202);
    }

    private function respond(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message], $status, ['Cache-Control' => 'no-store']);
    }
}
