<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Exception\UnverifiedSignedUriException;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class PasswordReset
{
    private UriSigner $signer;

    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%kernel.secret%')] private string $secret,
        #[Autowire('%env(DEFAULT_URI)%')] private string $baseUrl,
        #[Autowire('%env(MAILER_FROM)%')] private string $sender,
    ) {
        $this->signer = new UriSigner($secret);
    }

    public function createUrl(User $user, ?\DateTimeImmutable $expiresAt = null): string
    {
        $query = http_build_query(['id' => $user->getId(), 'version' => $this->version($user)]);
        $url = rtrim($this->baseUrl, '/') . '/reinitialiser-mot-de-passe?' . $query;

        return $this->signer->sign($url, $expiresAt ?? new \DateTimeImmutable('+1 hour'));
    }

    public function verify(string $url, User $user): void
    {
        $this->signer->verify($url);
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $parameters);
        $expectedPath = rtrim($this->baseUrl, '/') . '/reinitialiser-mot-de-passe';
        $path = explode('?', $url, 2)[0];

        if (
            $path !== $expectedPath
            || (string) ($parameters['id'] ?? '') !== (string) $user->getId()
            || !is_string($parameters['version'] ?? null)
            || !hash_equals($this->version($user), $parameters['version'])
        ) {
            throw new UnverifiedSignedUriException();
        }
    }

    private function version(User $user): string
    {
        return hash_hmac('sha256', $user->getEmail() . ':' . $user->getPassword(), $this->secret);
    }

    public function send(User $user): void
    {
        $message = (new Email())
            ->from(Address::create($this->sender))
            ->replyTo('le.grand.cinema.contact@gmail.com')
            ->to($user->getEmail())
            ->subject('Réinitialisez votre mot de passe du Grand Cinéma')
            ->text(
                'Bonjour ' . $user->getFirstName() . ",\n\n"
                . "Pour choisir un nouveau mot de passe, ouvrez ce lien valable une heure.\n\n"
                . $this->createUrl($user) . "\n\n"
                . "Si vous n’avez pas demandé ce changement, ignorez ce message. Votre mot de passe reste inchangé.\n\n"
                . 'Le Grand Cinéma'
            );
        $this->mailer->send($message);
    }
}
