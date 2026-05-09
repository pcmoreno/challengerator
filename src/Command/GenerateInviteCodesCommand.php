<?php
declare(strict_types=1);

namespace App\Command;

use App\Entity\Doctrine\DbInviteCode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:generate-invite-codes', description: 'Generate N invite codes for challenge creation')]
class GenerateInviteCodesCommand extends Command
{
    public function __construct(private EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('count', InputArgument::OPTIONAL, 'Number of codes to generate', 1);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = (int)$input->getArgument('count');

        for ($i = 0; $i < $count; $i++) {
            $code = bin2hex(random_bytes(16));
            $this->em->persist(new DbInviteCode($code));
        }
        $this->em->flush();

        $io->success("Generated $count invite code(s).");
        return Command::SUCCESS;
    }
}
