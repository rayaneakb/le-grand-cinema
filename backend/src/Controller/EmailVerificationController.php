<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\EmailVerification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Exception\ExpiredSignedUriException;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class EmailVerificationController
{
    #[Route('/api/verify-email', name: 'api_verify_email', methods: ['GET'])]
    public function verify(
        Request $request,
        UserRepository $users,
        EmailVerification $verification,
        EntityManagerInterface $entityManager,
        #[Autowire('%env(DEFAULT_URI)%')] string $baseUrl,
    ): JsonResponse {
        $parameters = $request->query->all();
        $id = $parameters['id'] ?? null;
        $user = is_string($id) && ctype_digit($id) ? $users->find($id) : null;

        if ($user === null) {
            return $this->respond('invalid_link', 'Ce lien d’activation est invalide.', 400);
        }

        // Le domaine vient de la configuration, pas de l’en-tête Host de la requête.
        $query = $request->server->get('QUERY_STRING', '');
        $uri = rtrim($baseUrl, '/') . '/activation?' . $query;

        try {
            $verification->verify($uri, $user);
        } catch (ExpiredSignedUriException) {
            return $this->respond('expired_link', 'Ce lien a expiré. Demandez un nouveau mail d’activation.', 410);
        } catch (SignedUriException) {
            return $this->respond('invalid_link', 'Ce lien d’activation est invalide.', 400);
        }

        if ($user->getEmailVerifiedAt() !== null) {
            return $this->respond('already_verified', 'Votre adresse mail est déjà vérifiée.', 200);
        }

        $user->setEmailVerifiedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $entityManager->flush();

        return $this->respond('verified', 'Votre adresse mail est vérifiée.', 200);
    }

    #[Route('/api/resend-verification', name: 'api_resend_verification', methods: ['POST'])]
    public function resend(
        Request $request,
        UserRepository $users,
        EmailVerification $verification,
        ValidatorInterface $validator,
        #[Autowire(service: 'limiter.email_verification')] RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->respond('invalid_format', 'La demande doit être envoyée au format JSON.', 415);
        }

        try {
            $payload = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->respond('invalid_json', 'Le contenu JSON est invalide.', 400);
        }

        if (!$payload instanceof \stdClass || !is_string($payload->email ?? null)) {
            return $this->respond('invalid_email', 'Veuillez saisir une adresse mail valide.', 422);
        }

        $email = strtolower(trim($payload->email));
        $constraints = [new Assert\NotBlank(), new Assert\Length(max: 180), new Assert\Email()];

        if (count($validator->validate($email, $constraints)) !== 0) {
            return $this->respond('invalid_email', 'Veuillez saisir une adresse mail valide.', 422);
        }

        $limit = $limiter->create(hash('sha256', $email))->consume();

        if (!$limit->isAccepted()) {
            $response = $this->respond('too_many_requests', 'Patientez une minute avant de demander un nouveau mail.', 429);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }

        $user = $users->findOneBy(['email' => $email]);

        if ($user !== null && $user->getEmailVerifiedAt() === null) {
            try {
                $verification->send($user);
            } catch (TransportExceptionInterface) {
                return $this->respond('mail_unavailable', 'Le service mail est indisponible. Réessayez plus tard.', 503);
            }
        }

        return $this->respond(
            'request_accepted',
            'Si un compte attend une activation à cette adresse, un nouveau mail vous sera envoyé.',
            202,
        );
    }

    private function respond(string $status, string $message, int $code): JsonResponse
    {
        $response = new JsonResponse(['status' => $status, 'message' => $message], $code);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
