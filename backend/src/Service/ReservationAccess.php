<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;

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

        return $this->signer->sign($this->url($booking['reference']), $expiresAt);
    }

    public function verify(string $reference, string $query): void
    {
        $this->signer->verify($this->url($reference) . '?' . $query);
    }

    private function url(string $reference): string
    {
        return rtrim($this->baseUrl, '/') . '/reservations/' . rawurlencode($reference);
    }
}
