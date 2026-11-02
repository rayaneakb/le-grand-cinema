<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Routing\Attribute\Route;

class SessionController
{
    #[Route('/api/csrf', name: 'api_csrf', methods: ['GET'])]
    public function csrf(CsrfTokenManagerInterface $csrf): JsonResponse
    {
        return $this->respond(['csrfToken' => $csrf->getToken('api')->getValue()]);
    }

    #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('La connexion doit être traitée par le pare-feu Symfony.');
    }

    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('La déconnexion doit être traitée par le pare-feu Symfony.');
    }

    #[Route('/api/session', name: 'api_session', methods: ['GET'])]
    public function current(Request $request, #[CurrentUser] ?User $user, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        if ($user === null) {
            return $this->respond(['user' => null]);
        }

        return $this->respond([
            'user' => [
                'id' => $user->getId(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ],
            'csrfToken' => $csrf->getToken('api')->getValue(),
            'logoutToken' => $csrf->getToken('logout')->getValue(),
            'expiresAt' => $request->getSession()->get('last_activity', time()) + 600,
        ]);
    }

    #[Route('/api/activity', name: 'api_activity', methods: ['POST'])]
    public function activity(Request $request, #[CurrentUser] ?User $user, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        if ($user === null) {
            return $this->respond(['message' => 'Votre session a expiré. Veuillez vous reconnecter.'], 401);
        }

        if (!$csrf->isTokenValid(new CsrfToken('api', $request->headers->get('X-CSRF-Token', '')))) {
            return $this->respond(['message' => 'Demande non autorisée.'], 403);
        }

        $request->getSession()->set('last_activity', time());

        return $this->respond(['status' => 'active']);
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
