<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpFoundation\Exception\UnverifiedSignedUriException;

class ReservationAccess
{
    private UriSigner $signer;

    public function __construct(
        #[Autowire('%kernel.secret%')] string $secret,
        #[Autowire('%env(DEFAULT_URI)%')] private string $baseUrl,
    ) {
        $this->signer = new UriSigner($secret);
    }

    public function createUrl(array $booking): string
    {
        $expiresAt = (new \DateTimeImmutable($booking['startsAt']))->modify('+30 days');

        $url = $this->url($booking['reference']);
        if (!empty($booking['accessVersion'])) {
            $url .= '?' . http_build_query(['version' => $booking['accessVersion']]);
        }

        return $this->signer->sign($url, $expiresAt);
    }

    public function verify(string $reference, string $query, ?string $version = null): void
    {
        $this->signer->verify($this->url($reference) . '?' . $query);
        parse_str($query, $parameters);
        if ($version !== null && (!is_string($parameters['version'] ?? null) || !hash_equals($version, $parameters['version']))) {
            throw new UnverifiedSignedUriException();
        }
    }

    private function url(string $reference): string
    {
        return rtrim($this->baseUrl, '/') . '/reservations/' . rawurlencode($reference);
    }
}
