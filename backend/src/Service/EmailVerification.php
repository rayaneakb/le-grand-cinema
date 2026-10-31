<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Exception\UnverifiedSignedUriException;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class EmailVerification
{
    private UriSigner $signer;

    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%kernel.secret%')] string $secret,
        #[Autowire('%env(DEFAULT_URI)%')] private string $baseUrl,
        #[Autowire('%env(MAILER_FROM)%')] private string $sender,
    ) {
        $this->signer = new UriSigner($secret);
    }

    public function createUrl(User $user, ?\DateTimeImmutable $expiresAt = null): string
    {
        if ($user->getId() === null) {
            throw new \LogicException('Le compte doit être enregistré avant de créer son lien.');
        }

        $query = http_build_query([
            'id' => $user->getId(),
            'emailHash' => hash('sha256', $user->getEmail()),
        ]);
        $url = rtrim($this->baseUrl, '/') . '/activation?' . $query;
        $expiresAt ??= new \DateTimeImmutable('+1 hour');

        return $this->signer->sign($url, $expiresAt);
    }

    public function verify(string $uri, User $user): void
    {
        $this->signer->verify($uri);
        parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $parameters);

        if (
            (string) ($parameters['id'] ?? '') !== (string) $user->getId()
            || !is_string($parameters['emailHash'] ?? null)
            || !hash_equals(hash('sha256', $user->getEmail()), $parameters['emailHash'])
        ) {
            throw new UnverifiedSignedUriException();
        }
    }

    public function send(User $user): void
    {
        $url = $this->createUrl($user);
        $message = (new Email())
            ->from(Address::create($this->sender))
            ->replyTo('le.grand.cinema.contact@gmail.com')
            ->to($user->getEmail())
            ->subject('Activez votre compte au Grand Cinéma')
            ->text(
                "Bonjour " . $user->getFirstName() . ",\n\n"
                . "Pour activer votre compte au Grand Cinéma, ouvrez ce lien valable une heure.\n\n"
                . $url . "\n\n"
                . "Si vous n’avez pas demandé ce compte, ignorez ce message.\n\n"
                . "Le Grand Cinéma"
            );

        $this->mailer->send($message);
    }
}
