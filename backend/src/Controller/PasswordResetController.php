<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\PasswordReset;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Exception\SignedUriException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class PasswordResetController
{
    #[Route('/api/forgot-password', name: 'api_forgot_password', methods: ['POST'])]
    public function requestReset(
        Request $request,
        UserRepository $users,
        PasswordReset $reset,
        ValidatorInterface $validator,
        #[Autowire(service: 'limiter.password_reset')] RateLimiterFactoryInterface $limiter,
    ): JsonResponse {
        $data = $this->payload($request);
        if ($data === null || !is_string($data->email ?? null)) {
            return $this->respond('invalid_request', 'Veuillez saisir une adresse mail valide.', 400);
        }
        $email = strtolower(trim($data->email));
        if (count($validator->validate($email, [new Assert\NotBlank(), new Assert\Length(max: 180), new Assert\Email()])) !== 0) {
            return $this->respond('invalid_email', 'Veuillez saisir une adresse mail valide.', 422);
        }
        $limit = $limiter->create(hash('sha256', $email))->consume();
        if (!$limit->isAccepted()) {
            $response = $this->respond('too_many_requests', 'Patientez une minute avant de demander un nouveau lien.', 429);
            $response->headers->set('Retry-After', (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()));

            return $response;
        }
        $user = $users->findOneBy(['email' => $email]);
        if ($user !== null && $user->getEmailVerifiedAt() !== null) {
            try {
                $reset->send($user);
            } catch (TransportExceptionInterface) {
                return $this->respond('mail_unavailable', 'Le service mail est indisponible. Réessayez plus tard.', 503);
            }
        }

        return $this->respond('request_accepted', 'Si cette adresse mail correspond à un compte activé, vous recevrez un lien pour réinitialiser votre mot de passe.', 202);
    }

    #[Route('/api/reset-password', name: 'api_reset_password', methods: ['POST'])]
    public function reset(
        Request $request,
        UserRepository $users,
        PasswordReset $reset,
        ValidatorInterface $validator,
        UserPasswordHasherInterface $hasher,
        Connection $connection,
    ): JsonResponse {
        $data = $this->payload($request);
        if ($data === null || !is_string($data->url ?? null) || strlen($data->url) > 2048) {
            return $this->invalidLink();
        }
        parse_str(parse_url($data->url, PHP_URL_QUERY) ?? '', $parameters);
        $id = $parameters['id'] ?? null;
        $user = is_string($id) && ctype_digit($id) ? $users->find($id) : null;
        if ($user === null || $user->getEmailVerifiedAt() === null) {
            return $this->invalidLink();
        }
        try {
            $reset->verify($data->url, $user);
        } catch (SignedUriException) {
            return $this->invalidLink();
        }
        $minimumLength = array_diff($user->getRoles(), ['ROLE_USER']) ? 15 : 10;
        $constraints = new Assert\Sequentially([
            new Assert\Type(type: 'string', message: 'Veuillez saisir un mot de passe.'),
            new Assert\NotBlank(message: 'Veuillez saisir un mot de passe.', normalizer: 'trim'),
            new Assert\Length(
                min: $minimumLength,
                max: 128,
                minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.',
                maxMessage: 'Le mot de passe ne doit pas dépasser 128 caractères.',
            ),
        ]);
        $errors = $validator->validate($data->password ?? null, $constraints);
        if (count($errors) !== 0) {
            return $this->respond('invalid_password', (string) $errors[0]->getMessage(), 422);
        }

        $oldHash = $user->getPassword();
        $newHash = $hasher->hashPassword($user, $data->password);
        // Le contrôle et le changement atomique empêchent deux utilisations du même lien.
        $changed = $connection->executeStatement(
            'UPDATE "user" SET password = :newHash WHERE id = :id AND password = :oldHash',
            ['newHash' => $newHash, 'id' => $user->getId(), 'oldHash' => $oldHash],
        );
        if ($changed !== 1) {
            return $this->invalidLink();
        }

        return $this->respond('password_changed', 'Votre mot de passe est modifié. Connectez-vous avec le nouveau.', 200);
    }

    private function payload(Request $request): ?\stdClass
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return null;
        }
        try {
            $data = json_decode($request->getContent(), flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return $data instanceof \stdClass ? $data : null;
    }

    private function invalidLink(): JsonResponse
    {
        return $this->respond('invalid_link', 'Ce lien est invalide ou a expiré. Demandez un nouveau lien.', 400);
    }

    private function respond(string $status, string $message, int $code): JsonResponse
    {
        $response = new JsonResponse(['status' => $status, 'message' => $message], $code);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
