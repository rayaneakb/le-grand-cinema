<?php

namespace App\Controller;

use App\Entity\User;
use App\Exception\ReservationProblem;
use App\Service\ReservationManagement;
use App\Service\ReservationPdf;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class ReservationManagementController
{
    #[Route('/api/reservations/{reference}', name: 'api_reservation_consult', requirements: ['reference' => '[A-F0-9]{16}'], methods: ['GET'])]
    public function consult(Request $request, string $reference, ReservationManagement $management, #[CurrentUser] ?User $user): JsonResponse
    {
        try {
            return $this->respond(['reservation' => $management->consult($reference, $request->server->get('QUERY_STRING', ''), $user)]);
        } catch (ReservationProblem $problem) {
            return $this->respond(['message' => $problem->getMessage()], $problem->getCode());
        }
    }

    #[Route('/api/reservations/{reference}/pdf', name: 'api_reservation_pdf', requirements: ['reference' => '[A-F0-9]{16}'], methods: ['GET'])]
    public function pdf(Request $request, string $reference, ReservationManagement $management, ReservationPdf $pdf, #[CurrentUser] ?User $user): Response
    {
        try {
            $booking = $management->consult($reference, $request->server->get('QUERY_STRING', ''), $user);
            if ($booking['status'] !== 'confirmed') {
                return $this->respond(['message' => 'Cette réservation est annulée. Vous ne pouvez plus télécharger sa confirmation PDF.'], 409);
            }
        } catch (ReservationProblem $problem) {
            return $this->respond(['message' => $problem->getMessage()], $problem->getCode());
        }

        return new Response($pdf->generate($booking), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', 'reservation-' . $reference . '.pdf'),
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route('/api/reservations/{reference}/cancel', name: 'api_reservation_cancel', requirements: ['reference' => '[A-F0-9]{16}'], methods: ['POST'])]
    public function cancel(Request $request, string $reference, ReservationManagement $management, CsrfTokenManagerInterface $csrf, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$csrf->isTokenValid(new CsrfToken('api', $request->headers->get('X-CSRF-Token', '')))) {
            return $this->respond(['message' => 'Votre formulaire a expiré. Réessayez.'], 403);
        }
        try {
            return $this->respond($management->cancel($reference, $request->server->get('QUERY_STRING', ''), $user));
        } catch (ReservationProblem $problem) {
            return $this->respond(['message' => $problem->getMessage()], $problem->getCode());
        }
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
