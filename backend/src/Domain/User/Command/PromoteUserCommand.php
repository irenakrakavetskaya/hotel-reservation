<?php

declare(strict_types=1);

namespace App\Domain\User\Command;

use App\Domain\User\Entity\User;
use App\Domain\User\Exception\UserNotFoundException;
use App\Domain\User\Service\UserRoleService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Bootstraps the first admin: `php bin/console app:user:promote you@example.com --role=ROLE_ADMIN`. */
#[AsCommand(name: 'app:user:promote', description: 'Sets the elevated role of an existing user (ROLE_STAFF or ROLE_ADMIN).')]
final class PromoteUserCommand extends Command
{
    public function __construct(private readonly UserRoleService $roles)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email of an existing user')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'ROLE_STAFF or ROLE_ADMIN', 'ROLE_ADMIN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $role = (string) $input->getOption('role');

        if (!in_array($role, User::ASSIGNABLE_ROLES, true)) {
            $io->error(sprintf('Role must be one of: %s.', implode(', ', User::ASSIGNABLE_ROLES)));

            return Command::INVALID;
        }

        try {
            $user = $this->roles->setRolesByEmail($email, [$role]);
        } catch (UserNotFoundException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('%s now has roles: %s', $user->getUserIdentifier(), implode(', ', $user->getRoles())));

        return Command::SUCCESS;
    }
}
