<?php

namespace App\Controller;

use App\Service\FilmAdministration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/films')]
#[IsGranted('ROLE_EMPLOYEE')]
class AdminFilmController extends AbstractController
{
    #[Route('', name: 'api_admin_films', methods: ['GET'])]
    public function index(FilmAdministration $films): JsonResponse
    {
        return $this->respond(['films' => $films->all()]);
    }

    #[Route('', name: 'api_admin_film_create', methods: ['POST'])]
    #[Route('/{id}', name: 'api_admin_film_edit', requirements: ['id' => '[1-9][0-9]*'], methods: ['POST'])]
    public function save(Request $request, FilmAdministration $films, CsrfTokenManagerInterface $csrf, ?int $id = null): JsonResponse
    {
        if (!$csrf->isTokenValid(new CsrfToken('api', $request->headers->get('X-CSRF-Token', '')))) {
            return $this->respond(['message' => 'Votre formulaire a expiré. Réessayez.'], 403);
        }
        if ($request->getContentTypeFormat() !== 'form') {
            return $this->respond(['message' => 'Envoyez le formulaire avec son affiche.'], 415);
        }
        $files = $request->files->all();
        if (array_diff(array_keys($files), ['poster']) || (isset($files['poster']) && !$files['poster'] instanceof UploadedFile)) {
            return $this->respond(['message' => 'Une seule affiche est attendue.'], 422);
        }
        try {
            return $this->respond($films->save($id, $request->request->all(), $files['poster'] ?? null), $id === null ? 201 : 200);
        } catch (\InvalidArgumentException $failure) {
            return $this->respond(['message' => $failure->getMessage()], 422);
        } catch (\OutOfBoundsException $failure) {
            return $this->respond(['message' => $failure->getMessage()], 404);
        } catch (\DomainException $failure) {
            return $this->respond(['message' => $failure->getMessage()], 409);
        } catch (\RuntimeException $failure) {
            return $this->respond(['message' => 'Le film n’a pas pu être enregistré. Vérifiez le stockage des affiches et réessayez.'], 503);
        }
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }
}
