<?php

namespace App\Controller;

use App\Service\ScreeningAdministration;
use App\Service\ScreeningNoticeMailer;
use App\Service\ScreeningProblem;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/screenings')]
#[IsGranted('ROLE_EMPLOYEE')]
class AdminScreeningController extends AbstractController
{
    #[Route('', name: 'api_admin_screenings', methods: ['GET'])]
    public function index(Request $request, ScreeningAdministration $sessions): JsonResponse
    {
        try {
            return $this->respond($sessions->list($request->query->get('date', '')));
        } catch (ScreeningProblem $error) {
            return $this->respond(['message' => $error->getMessage()], $error->status);
        }
    }

    #[Route('', name: 'api_admin_screening_create', methods: ['POST'])]
    #[Route('/{id}', name: 'api_admin_screening_edit', requirements: ['id' => '[1-9][0-9]*'], methods: ['POST'])]
    public function write(
        Request $request,
        ScreeningAdministration $sessions,
        ScreeningNoticeMailer $notices,
        CsrfTokenManagerInterface $csrf,
        Connection $connection,
        ?int $id = null,
        ?string $action = null,
    ): JsonResponse
    {
        if (!$csrf->isTokenValid(new CsrfToken('api', $request->headers->get('X-CSRF-Token', '')))) {
            return $this->respond(['message' => 'Votre formulaire a expiré. Réessayez.'], 403);
        }
        try {
            if ($action === 'cancel') {
                return $this->respond($sessions->cancel($id));
            }
            if ($action === 'retry-emails') {
                if (!$connection->fetchOne('SELECT id FROM screening WHERE id = ?', [$id])) {
                    throw new ScreeningProblem('Cette séance est introuvable.', 404);
                }
                return $this->respond(['pendingEmails' => $notices->sendPending($id), 'message' => 'Les notifications ont été relancées.']);
            }
            if ($request->getContentTypeFormat() !== 'json') {
                return $this->respond(['message' => 'Le format JSON est attendu.'], 415);
            }
            try {
                $data = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ScreeningProblem('Le contenu JSON est invalide.', 400);
            }
            if (!$data instanceof \stdClass) {
                throw new ScreeningProblem('Le formulaire est invalide.');
            }
            return $this->respond($sessions->save($id, (array) $data), $id ? 200 : 201);
        } catch (ScreeningProblem $error) {
            return $this->respond(['message' => $error->getMessage()], $error->status);
        }
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }
}
