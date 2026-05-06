<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Console\Command;

use MageUpgrade\AutoUpgrader\Api\UpgradeManagerInterface;
use MageUpgrade\AutoUpgrader\Api\Data\UpgradeLogInterface;
use MageUpgrade\AutoUpgrader\Service\ProgressTracker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class UpgradeCommand extends Command
{
    public function __construct(
        private readonly UpgradeManagerInterface $upgradeManager,
        private readonly ProgressTracker $progressTracker
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('autoupgrader:upgrade')
            ->setDescription('Execute automated Magento upgrade to target version')
            ->addArgument('target_version', InputArgument::REQUIRED, 'Target Magento version')
            ->addOption('no-patches', null, InputOption::VALUE_NONE, 'Skip patch inclusion')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt')
            ->addOption('upgrade-id', null, InputOption::VALUE_REQUIRED, 'Existing upgrade ID (for background mode)')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Progress file token (for background mode)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $targetVersion = $input->getArgument('target_version');
        $includePatches = !$input->getOption('no-patches');
        $autoConfirm = $input->getOption('yes');
        $upgradeId = $input->getOption('upgrade-id');
        $token = $input->getOption('token');

        // Background mode: upgrade-id and token provided by the admin controller
        $backgroundMode = ($upgradeId !== null && $token !== null);

        $io->title('AutoUpgrader - Upgrade to ' . $targetVersion);

        if ($backgroundMode) {
            return $this->executeBackground($io, (int) $upgradeId);
        }

        return $this->executeInteractive($io, $input, $output, $targetVersion, $includePatches, $autoConfirm);
    }

    private function executeBackground(SymfonyStyle $io, int $upgradeId): int
    {
        $io->text("Background mode: upgrade_id=$upgradeId");

        $upgradeLog = $this->upgradeManager->confirmAndExecute($upgradeId);

        // Write final state to progress file
        $this->progressTracker->updateFileProgress($upgradeId);

        if ($upgradeLog->getStatus() === UpgradeLogInterface::STATUS_COMPLETED) {
            $io->success('Upgrade completed successfully!');
            return Command::SUCCESS;
        }

        $io->error('Upgrade failed: ' . $upgradeLog->getErrorMessage());
        return Command::FAILURE;
    }

    private function executeInteractive(
        SymfonyStyle $io,
        InputInterface $input,
        OutputInterface $output,
        string $targetVersion,
        bool $includePatches,
        bool $autoConfirm
    ): int {
        // Step 1: Scan and prepare
        $io->section('Phase 1: Scanning and preparing upgrade...');
        $upgradeLog = $this->upgradeManager->startUpgrade($targetVersion, $includePatches);

        if ($upgradeLog->getStatus() === UpgradeLogInterface::STATUS_FAILED) {
            $io->error('Upgrade preparation failed: ' . $upgradeLog->getErrorMessage());
            return Command::FAILURE;
        }

        // Show scan summary
        $io->success('Scan completed. Upgrade is ready.');
        $io->text("Upgrade ID: {$upgradeLog->getUpgradeId()}");
        $io->text("From: {$upgradeLog->getFromVersion()} -> To: {$upgradeLog->getToVersion()}");

        // Step 2: Ask for confirmation
        if (!$autoConfirm) {
            $io->newLine();
            $io->caution('This will modify your Magento installation. A backup will be created first.');
            $io->text('The following steps will be executed:');
            $io->listing([
                'Create full backup (database + files)',
                'Auto-fix detected compatibility issues',
                'Upgrade third-party extensions to compatible versions',
                'Run Composer update to ' . $targetVersion,
                'Execute setup:upgrade',
                'Compile dependency injection',
                'Deploy static content',
                'Flush cache',
                'Verify installation',
            ]);

            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                '<question>Do you want to proceed with the upgrade? (y/N)</question> ',
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                $io->warning('Upgrade cancelled by user.');
                return Command::SUCCESS;
            }
        }

        // Step 3: Execute
        $io->section('Phase 2: Executing upgrade...');
        $io->progressStart(100);

        $upgradeLog = $this->upgradeManager->confirmAndExecute((int) $upgradeLog->getUpgradeId());

        $io->progressFinish();
        $io->newLine();

        if ($upgradeLog->getStatus() === UpgradeLogInterface::STATUS_COMPLETED) {
            $io->success([
                'Upgrade completed successfully!',
                'Upgraded from ' . $upgradeLog->getFromVersion() . ' to ' . $upgradeLog->getToVersion(),
                'Backup location: ' . $upgradeLog->getBackupPath(),
            ]);
            return Command::SUCCESS;
        }

        $io->error([
            'Upgrade failed!',
            'Error: ' . $upgradeLog->getErrorMessage(),
            'A backup was created at: ' . $upgradeLog->getBackupPath(),
            'Run: autoupgrader:rollback ' . $upgradeLog->getUpgradeId() . ' to restore.',
        ]);

        return Command::FAILURE;
    }
}
