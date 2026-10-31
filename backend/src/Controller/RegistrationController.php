<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Validator\RegistrationValidator;
use App\Service\EmailVerification;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        RegistrationValidator $validator,
        UserRepository $users,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        EmailVerification $verification,
    ): JsonResponse {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->respond(['message' => 'La demande doit être envoyée au format JSON.'], 415);
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
            return $this->respond(['errors' => $errors], 422);
        }

        $email = strtolower(trim($data['email']));

        if ($users->findOneBy(['email' => $email]) !== null) {
            return $this->emailAlreadyUsed();
        }

        $user = new User();
        $user->setFirstName(trim($data['firstName']));
        $user->setLastName(trim($data['lastName']));
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setPassword($passwordHasher->hashPassword($user, $data['password']));

        try {
            $entityManager->persist($user);
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Deux demandes simultanées peuvent utiliser le même mail.
            return $this->emailAlreadyUsed();
        }

        try {
            $verification->send($user);
        } catch (TransportExceptionInterface) {
            return $this->respond([
                'status' => 'pending_verification',
                'emailSent' => false,
                'message' => 'Votre compte est créé, mais le mail n’a pas pu être envoyé. Vous pouvez demander un nouvel envoi.',
            ], 201);
        }

        return $this->respond(['status' => 'pending_verification', 'emailSent' => true], 201);
    }

    private function emailAlreadyUsed(): JsonResponse
    {
        return $this->respond([
            'errors' => ['email' => ['Un compte est déjà associé à cette adresse mail.']],
        ], 409);
    }

    private function respond(array $data, int $status): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
