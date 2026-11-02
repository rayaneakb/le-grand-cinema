<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class CinemaSettings
{
    public const SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column]
    private int $ticketPriceCents;

    public function __construct(int $ticketPriceCents)
    {
        $this->setTicketPriceCents($ticketPriceCents);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTicketPriceCents(): int
    {
        return $this->ticketPriceCents;
    }

    public function setTicketPriceCents(int $ticketPriceCents): static
    {
        if ($ticketPriceCents <= 0) {
            throw new \InvalidArgumentException('Le tarif doit être strictement supérieur à zéro.');
        }

        $this->ticketPriceCents = $ticketPriceCents;

        return $this;
    }
}
