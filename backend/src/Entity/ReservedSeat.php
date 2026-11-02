<?php

namespace App\Entity;

use App\Repository\ReservedSeatRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservedSeatRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\UniqueConstraint(
    name: 'uniq_active_seat_screening',
    fields: ['screening', 'seat'],
    options: ['where' => '(released_at IS NULL)']
)]
class ReservedSeat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Reservation $reservation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Screening $screening = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Seat $seat = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function validateAssociations(): void
    {
        if ($this->reservation === null || $this->screening === null || $this->seat === null) {
            throw new \DomainException('La réservation, la séance et le siège sont obligatoires.');
        }

        $reservationScreening = $this->reservation->getScreening();
        if (
            $reservationScreening === null ||
            !$this->sameScreening($reservationScreening, $this->screening)
        ) {
            throw new \DomainException(
                'La place doit concerner la même séance que la réservation.'
            );
        }

        $seatRoom = $this->seat->getRoom();
        $screeningRoom = $this->screening->getRoom();
        if (
            $seatRoom === null ||
            $screeningRoom === null ||
            !$this->sameRoom($seatRoom, $screeningRoom)
        ) {
            throw new \DomainException('Le siège ne correspond pas à la salle de cette séance.');
        }
    }

    private function sameScreening(Screening $first, Screening $second): bool
    {
        return $first === $second ||
            ($first->getId() !== null && $first->getId() === $second->getId());
    }

    private function sameRoom(Room $first, Room $second): bool
    {
        return $first === $second ||
            ($first->getId() !== null && $first->getId() === $second->getId());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function setReservation(Reservation $reservation): static
    {
        $this->reservation = $reservation;

        return $this;
    }

    public function getScreening(): ?Screening
    {
        return $this->screening;
    }

    public function setScreening(Screening $screening): static
    {
        $this->screening = $screening;

        return $this;
    }

    public function getSeat(): ?Seat
    {
        return $this->seat;
    }

    public function setSeat(Seat $seat): static
    {
        $this->seat = $seat;

        return $this;
    }

    public function getReleasedAt(): ?\DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function setReleasedAt(?\DateTimeImmutable $releasedAt): static
    {
        $this->releasedAt = $releasedAt;

        return $this;
    }
}
