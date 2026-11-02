<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Validator\RegistrationValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-staff', description: 'Crée un compte employé ou direction sans inscription publique.')]
class CreateStaffCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $manager,
        private UserRepository $users,
        private RegistrationValidator $validator,
        private UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->isInteractive()) {
            $io->error('Cette commande demande une saisie interactive du mot de passe.');

            return Command::INVALID;
        }
        $email = strtolower(trim((string) $io->ask('Adresse mail')));
        $firstName = trim((string) $io->ask('Prénom'));
        $lastName = trim((string) $io->ask('Nom'));
        $role = $io->choice('Type de compte', ['Employé', 'Direction'], 'Employé');
        if ($this->users->findOneBy(['email' => $email]) !== null) {
            $io->error('Un compte existe déjà à cette adresse. Aucun rôle ni mot de passe n’a été modifié.');

            return Command::FAILURE;
        }
        $password = $io->askHidden('Mot de passe (15 caractères minimum)', static function (?string $value): string {
            if ($value === null || trim($value) === '' || mb_strlen($value) < 15 || mb_strlen($value) > 128) {
                throw new \RuntimeException('Choisissez un mot de passe de 15 à 128 caractères.');
            }

            return $value;
        });
        $errors = $this->validator->validate(compact('email', 'firstName', 'lastName', 'password'));
        if ($errors !== []) {
            foreach ($errors as $messages) {
                $io->error(implode(' ', $messages));
            }

            return Command::INVALID;
        }
        $user = (new User())->setEmail($email)->setFirstName($firstName)->setLastName($lastName)
            ->setRoles([$role === 'Direction' ? 'ROLE_DIRECTOR' : 'ROLE_EMPLOYEE'])
            ->setEmailVerifiedAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $this->manager->persist($user);
        $this->manager->flush();
        $io->success('Le compte ' . strtolower($role) . ' est créé. Il peut se connecter avec son adresse mail.');

        return Command::SUCCESS;
    }
}
