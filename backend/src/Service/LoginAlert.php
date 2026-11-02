<?php

namespace App\Service;

use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

class LoginAlert
{
    public function __construct(
        private UserRepository $users,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        #[Autowire(service: 'limiter.login_alert')] private RateLimiterFactoryInterface $limiter,
        #[Autowire('%env(DEFAULT_URI)%')] private string $baseUrl,
        #[Autowire('%env(MAILER_FROM)%')] private string $sender,
    ) {
    }

    public function notify(Request $request): void
    {
        try {
            $data = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        $email = is_array($data) && is_string($data['email'] ?? null) ? strtolower(trim($data['email'])) : '';
        if (strlen($email) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        if (!$this->limiter->create(hash('sha256', $email))->consume()->isAccepted()) {
            return;
        }
        $user = $this->users->findOneBy(['email' => $email]);
        if ($user === null || $user->getEmailVerifiedAt() === null) {
            return;
        }
        $message = (new Email())
            ->from(Address::create($this->sender))
            ->to($user->getEmail())
            ->subject('Tentatives de connexion à votre compte du Grand Cinéma')
            ->text("Bonjour " . $user->getFirstName() . ",\n\n"
                . "Plusieurs tentatives de connexion ont échoué sur votre compte. Les nouvelles tentatives sont temporairement limitées.\n\n"
                . "Si vous n’êtes pas à l’origine de ces essais, vous pouvez changer votre mot de passe depuis cette page.\n"
                . rtrim($this->baseUrl, '/') . "/mot-de-passe-oublie\n\nLe Grand Cinéma");
        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface) {
            $this->logger->warning('Échec de l’envoi d’une alerte de connexion.');
        }
    }
}
