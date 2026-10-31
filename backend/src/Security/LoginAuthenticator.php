<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class LoginAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'api_login' && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        if ('json' !== $request->getContentTypeFormat()) {
            throw new BadCredentialsException();
        }

        try {
            $data = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadCredentialsException();
        }

        if (
            !is_array($data)
            || !is_string($data['email'] ?? null)
            || !is_string($data['password'] ?? null)
            || strlen($data['email']) > 180
            || mb_strlen($data['password']) > 128
        ) {
            throw new BadCredentialsException();
        }

        return new Passport(
            new UserBadge(strtolower(trim($data['email']))),
            new PasswordCredentials($data['password']),
            [new CsrfTokenBadge('api', $request->headers->get('X-CSRF-Token', ''))],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): JsonResponse
    {
        $request->getSession()->set('last_activity', time());
        $response = new JsonResponse(['status' => 'authenticated']);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): JsonResponse
    {
        $status = 401;
        $code = 'invalid_credentials';
        $message = 'Adresse mail ou mot de passe incorrect.';

        if ($exception instanceof EmailNotVerifiedException) {
            $status = 403;
            $code = 'email_not_verified';
            $message = $exception->getMessageKey();
        } elseif ($exception instanceof InvalidCsrfTokenException) {
            $status = 403;
            $code = 'invalid_csrf';
            $message = 'Le formulaire a expiré. Rechargez la page pour réessayer.';
        } elseif ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            $status = 429;
            $code = 'too_many_attempts';
            $message = 'Trop de tentatives. Patientez cinq minutes avant de réessayer.';
        }

        $response = new JsonResponse(['status' => $code, 'message' => $message], $status);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function start(Request $request, ?AuthenticationException $authException = null): JsonResponse
    {
        $response = new JsonResponse(['status' => 'authentication_required', 'message' => 'Veuillez vous connecter.'], 401);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
