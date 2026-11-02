<?php

namespace App\Command;

use App\Entity\{CinemaSettings, Film, Room, Screening, Seat};
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:load-demo',
    description: 'Ajoute la programmation de démonstration dans une base vide.'
)]
class LoadDemoCommand extends Command
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (
            $this->entityManager->getRepository(Film::class)->count([]) > 0 ||
            $this->entityManager->getRepository(Room::class)->count([]) > 0 ||
            $this->entityManager->getRepository(CinemaSettings::class)->count([]) > 0
        ) {
            $io->warning('La base contient déjà des données. Aucun ajout effectué.');

            return Command::SUCCESS;
        }

        $this->entityManager->wrapInTransaction(function (): void {
            $rooms = [];
            $configurations = [
                ['Premium', 300],
                ['Premium', 300],
                ['Standard', 180],
                ['Standard', 180],
                ['Standard', 180],
                ['Standard', 180],
                ['VIP', 120],
                ['VIP', 120],
                ['Événementielle', 70],
                ['Événementielle', 70]
            ];
            foreach ($configurations as $index => [$type, $capacity]) {
                $room = (new Room())->setName('Salle ' . ($index + 1))->setType($type);
                $this->entityManager->persist($room);
                $rooms[] = $room;
                for ($position = 0; $position < $capacity; ++$position) {
                    $seat = (new Seat())
                        ->setRoom($room)
                        ->setRowLabel(chr(65 + intdiv($position, 20)))
                        ->setNumber(($position % 20) + 1);
                    $this->entityManager->persist($seat);
                }
            }

            $films = [
                [
                    'Les jours de lumière',
                    112,
                    'Camille Laurent, Adrien Morel',
                    'De retour dans sa ville natale, une photographe retrouve un ami d’enfance. Ensemble, ils découvrent les histoires cachées derrière les images de son père.',
                    'lumiere'
                ],
                [
                    'Au-delà des étoiles',
                    138,
                    'Sarah Duval, Malik Benali',
                    'Une équipe d’explorateurs reçoit un mystérieux signal. Leur voyage les mène vers une planète où chaque découverte remet en question leur mission.',
                    'etoiles'
                ],
                [
                    'Un été en musique',
                    96,
                    'Jeanne Petit, Lucas Martin',
                    'Pour sauver le festival de leur quartier, trois voisins montent un groupe. Un seul problème, aucun d’eux ne sait jouer d’un instrument.',
                    'musique'
                ]
            ];
            $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
            foreach ($films as $index => [$title, $duration, $actors, $synopsis, $poster]) {
                $film = (new Film())
                    ->setTitle($title)
                    ->setDurationMinutes($duration)
                    ->setMainActors($actors)
                    ->setSynopsis($synopsis)
                    ->setIsShowing(true)
                    ->setPosterUrl('/demo/posters/' . $poster . '.svg')
                    ->setPosterPublicId('demo/' . $poster);
                $this->entityManager->persist($film);
                if ($index === 2) {
                    continue;
                }
                for ($day = 0; $day < 5; ++$day) {
                    foreach ($index === 0 ? ['14:30', '18:00'] : ['16:00', '20:00'] as $time) {
                        [$hour, $minute] = array_map('intval', explode(':', $time));
                        $start = $today
                            ->modify('+' . $day . ' days')
                            ->setTime($hour, $minute)
                            ->setTimezone(new \DateTimeZone('UTC'));
                        $screening = (new Screening())
                            ->setFilm($film)
                            ->setRoom($rooms[$index])
                            ->setStartsAt($start)
                            ->setEndsAt($start->modify('+' . $duration . ' minutes'));
                        $this->entityManager->persist($screening);
                    }
                }
            }
            $this->entityManager->persist(new CinemaSettings(1000));
        });
        $io->success(
            'Démonstration ajoutée : 3 films, 10 salles, 1 700 sièges, 20 séances et tarif de 10 €.'
        );

        return Command::SUCCESS;
    }
}
