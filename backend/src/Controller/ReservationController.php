<?php

namespace App\Controller;

use App\Entity\User;
use App\Exception\ReservationProblem;
use App\Service\ReservationService;
use App\Service\ReservationAccess;
use App\Service\ReservationConfirmationMailer;
use App\Validator\ReservationValidator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class ReservationController
{
    #[Route('/api/reservations', name: 'api_reservation_confirm', methods: ['POST'])]
    public function confirm(
        Request $request,
        ReservationValidator $validator,
        ReservationService $reservations,
        ReservationConfirmationMailer $confirmationMailer,
        ReservationAccess $access,
        CsrfTokenManagerInterface $csrf,
        #[Autowire('%kernel.secret%')] string $secret,
        #[CurrentUser] ?User $user,
    ): JsonResponse {
        if ($request->getContentTypeFormat() !== 'json') {
            return $this->respond(['message' => 'Le format JSON est attendu.'], 415);
        }
        if (!$csrf->isTokenValid(new CsrfToken('api', $request->headers->get('X-CSRF-Token', '')))) {
            return $this->respond(['message' => 'Votre formulaire a expiré. Réessayez.', 'status' => 'invalid_csrf'], 403);
        }
        try {
            $payload = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->respond(['message' => 'Le contenu JSON est invalide.'], 400);
        }
        if (!$payload instanceof \stdClass) {
            return $this->respond(['message' => 'La demande doit contenir un objet JSON.'], 400);
        }
        $data = (array) $payload;
        $errors = $validator->validate($data);
        if ($errors !== []) {
            return $this->respond(['message' => 'Vérifiez les informations saisies.', 'errors' => $errors], 422);
        }

        // La clé de nouvelle tentative ne permet pas de retrouver une réservation depuis une autre session.
        $key = hash_hmac('sha256', $request->getSession()->getId() . ':' . $data['confirmationKey'], $secret);
        try {
            $result = $reservations->confirm($data, $key, $user);
        } catch (ReservationProblem $problem) {
            return $this->respond(['message' => $problem->getMessage(), ...$problem->details], $problem->getCode());
        }

        $result['reservation']['managementUrl'] = $access->createUrl($result['reservation']);
        $mail = $confirmationMailer->send($key, $result['reservation']);

        return $this->respond(['status' => 'confirmed', ...$result, ...$mail], $result['replayed'] ? 200 : 201);
    }

    private function respond(array $data, int $status): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
