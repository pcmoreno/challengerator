<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:create-super-user', description: 'Create a super-admin user who can manage all challenges')]
class CreateSuperUserCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::REQUIRED, 'Username')
            ->addArgument('password', InputArgument::REQUIRED, 'Password');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = $input->getArgument('username');
        $password = $input->getArgument('password');

        $existing = $this->em->getRepository(User::class)->findOneBy(['username' => $username]);
        if ($existing !== null) {
            $io->error("User '$username' already exists.");
            return Command::FAILURE;
        }

        $user = new User($username);
        $user->setPassword($this->hasher->hashPassword($user, $password));
        $user->addRole('ROLE_SUPER_ADMIN');
        $user->verify();

        $this->em->persist($user);
        $this->em->flush();

        $io->success("Super-admin '$username' created.");
        return Command::SUCCESS;
    }
}
