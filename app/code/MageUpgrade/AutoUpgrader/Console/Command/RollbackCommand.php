<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Console\Command;

use MageUpgrade\AutoUpgrader\Api\UpgradeManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class RollbackCommand extends Command
{
    public function __construct(
        private readonly UpgradeManagerInterface $upgradeManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('autoupgrader:rollback')
            ->setDescription('Rollback a failed or completed upgrade using backup')
            ->addArgument('upgrade_id', InputArgument::REQUIRED, 'The upgrade ID to rollback');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $upgradeId = (int) $input->getArgument('upgrade_id');

        $io->title('AutoUpgrader - Rollback');
        $io->caution('This will restore your installation from the backup created before upgrade #' . $upgradeId);

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion(
            '<question>Are you sure you want to rollback? (y/N)</question> ',
            false
        );

        if (!$helper->ask($input, $output, $question)) {
            $io->warning('Rollback cancelled.');
            return Command::SUCCESS;
        }

        $io->section('Executing rollback...');

        try {
            $result = $this->upgradeManager->rollback($upgradeId);

            if ($result) {
                $io->success('Rollback completed successfully. Your store has been restored.');
                return Command::SUCCESS;
            }

            $io->error('Rollback failed. Please check logs and restore manually.');
            return Command::FAILURE;
        } catch (\Exception $e) {
            $io->error('Rollback error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
