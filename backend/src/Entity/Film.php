<?php

namespace App\Entity;

use App\Repository\FilmRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FilmRepository::class)]
class Film
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $synopsis = null;

    #[ORM\Column]
    private ?int $durationMinutes = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $mainActors = null;

    #[ORM\Column(length: 2048)]
    private ?string $posterUrl = null;

    #[ORM\Column(length: 255)]
    private ?string $posterPublicId = null;

    #[ORM\Column]
    private ?bool $isShowing = null;

    /**
     * @var Collection<int, Screening>
     */
    #[ORM\OneToMany(targetEntity: Screening::class, mappedBy: 'film')]
    private Collection $screenings;

    public function __construct()
    {
        $this->screenings = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getSynopsis(): ?string
    {
        return $this->synopsis;
    }

    public function setSynopsis(string $synopsis): static
    {
        $this->synopsis = $synopsis;

        return $this;
    }

    public function getDurationMinutes(): ?int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(int $durationMinutes): static
    {
        $this->durationMinutes = $durationMinutes;

        return $this;
    }

    public function getMainActors(): ?string
    {
        return $this->mainActors;
    }

    public function setMainActors(string $mainActors): static
    {
        $this->mainActors = $mainActors;

        return $this;
    }

    public function getPosterUrl(): ?string
    {
        return $this->posterUrl;
    }

    public function setPosterUrl(string $posterUrl): static
    {
        $this->posterUrl = $posterUrl;

        return $this;
    }

    public function getPosterPublicId(): ?string
    {
        return $this->posterPublicId;
    }

    public function setPosterPublicId(string $posterPublicId): static
    {
        $this->posterPublicId = $posterPublicId;

        return $this;
    }

    public function isShowing(): ?bool
    {
        return $this->isShowing;
    }

    public function setIsShowing(bool $isShowing): static
    {
        $this->isShowing = $isShowing;

        return $this;
    }

    /**
     * @return Collection<int, Screening>
     */
    public function getScreenings(): Collection
    {
        return $this->screenings;
    }

    public function addScreening(Screening $screening): static
    {
        if (!$this->screenings->contains($screening)) {
            $this->screenings->add($screening);
            $screening->setFilm($this);
        }

        return $this;
    }

    public function removeScreening(Screening $screening): static
    {
        if ($this->screenings->removeElement($screening)) {
            // set the owning side to null (unless already changed)
            if ($screening->getFilm() === $this) {
                $screening->setFilm(null);
            }
        }

        return $this;
    }
}
