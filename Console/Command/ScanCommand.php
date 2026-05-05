<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Console\Command;

use MageUpgrade\AutoUpgrader\Api\CompatibilityScannerInterface;
use MageUpgrade\AutoUpgrader\Api\VersionResolverInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ScanCommand extends Command
{
    public function __construct(
        private readonly CompatibilityScannerInterface $scanner,
        private readonly VersionResolverInterface $versionResolver,
        private readonly Json $json
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('autoupgrader:scan')
            ->setDescription('Run compatibility scan for a target Magento version')
            ->addArgument('target_version', InputArgument::REQUIRED, 'Target Magento version (e.g., 2.4.8)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $targetVersion = $input->getArgument('target_version');
        $currentVersion = $this->versionResolver->getCurrentVersion();

        $io->title('AutoUpgrader - Compatibility Scan');
        $io->text([
            "Current Version: <info>{$currentVersion}</info>",
            "Target Version:  <info>{$targetVersion}</info>",
            '',
        ]);

        $io->section('Running compatibility scan...');
        $result = $this->scanner->runScan($targetVersion);

        if ($result->getStatus() === 'failed') {
            $io->error('Scan failed.');
            return Command::FAILURE;
        }

        // Display summary
        $io->success('Scan completed!');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Total Issues', (string) $result->getTotalIssues()],
                ['Critical Issues', (string) $result->getCriticalIssues()],
                ['Warnings', (string) $result->getWarnings()],
                ['Auto-Fixable', (string) $result->getAutoFixable()],
            ]
        );

        // Display impacted files
        $impactedFiles = $this->json->unserialize($result->getImpactedFilesJson() ?? '[]');
        if (!empty($impactedFiles)) {
            $io->section('Impacted Files (' . count($impactedFiles) . ')');
            foreach ($impactedFiles as $file) {
                $io->text("  - {$file}");
            }
        }

        // Display issues
        $issues = $this->json->unserialize($result->getIssuesJson() ?? '[]');
        if (!empty($issues)) {
            $io->section('Issues Detail');
            $table = new Table($output);
            $table->setHeaders(['Severity', 'Category', 'File', 'Line', 'Description', 'Auto-Fix']);

            foreach ($issues as $issue) {
                $table->addRow([
                    $issue['severity'] ?? '',
                    $issue['category'] ?? '',
                    basename($issue['file_path'] ?? ''),
                    $issue['line_number'] ?? '-',
                    substr($issue['description'] ?? '', 0, 50),
                    ($issue['is_auto_fixable'] ?? false) ? 'Yes' : 'No',
                ]);
            }
            $table->render();
        }

        $io->text('');
        $io->text("Scan ID: <info>{$result->getScanId()}</info>");
        $io->text('Run <info>autoupgrader:upgrade ' . $targetVersion . '</info> to proceed with the upgrade.');

        return Command::SUCCESS;
    }
}
