<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Service;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Backup\Factory as BackupFactory;
use Magento\Framework\Filesystem;
use MageUpgrade\AutoUpgrader\Api\BackupManagerInterface;
use Psr\Log\LoggerInterface;

class BackupManager implements BackupManagerInterface
{
    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly Filesystem $filesystem,
        private readonly LoggerInterface $logger
    ) {
    }

    public function createBackup(string $label = ''): array
    {
        $timestamp = date('Ymd_His');
        $label = $label ?: 'autoupgrader_backup';
        $backupDir = $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/autoupgrader_backups';

        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0775, true);
        }

        $backupPath = $backupDir . '/' . $label . '_' . $timestamp;
        mkdir($backupPath, 0775, true);

        try {
            // Backup database
            $dbBackupFile = $backupPath . '/database.sql.gz';
            $this->backupDatabase($dbBackupFile);

            // Backup composer files
            $rootDir = $this->directoryList->getRoot();
            copy($rootDir . '/composer.json', $backupPath . '/composer.json');
            copy($rootDir . '/composer.lock', $backupPath . '/composer.lock');

            // Backup app/code (custom modules)
            $appCodeDir = $this->directoryList->getPath(DirectoryList::APP) . '/code';
            if (is_dir($appCodeDir)) {
                $this->backupDirectory($appCodeDir, $backupPath . '/app_code.tar.gz');
            }

            // Backup app/etc (configuration)
            $appEtcDir = $this->directoryList->getPath(DirectoryList::APP) . '/etc';
            if (is_dir($appEtcDir)) {
                $this->backupDirectory($appEtcDir, $backupPath . '/app_etc.tar.gz');
            }

            $size = $this->getDirectorySize($backupPath);

            return [
                'backup_id' => $timestamp,
                'path' => $backupPath,
                'size' => $this->formatBytes($size),
                'created_at' => date('Y-m-d H:i:s'),
                'includes' => ['database', 'composer_files', 'custom_modules', 'configuration'],
            ];
        } catch (\Exception $e) {
            $this->logger->error('AutoUpgrader backup failed: ' . $e->getMessage());
            throw new \RuntimeException('Backup failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function restoreBackup(string $backupPath): bool
    {
        if (!is_dir($backupPath)) {
            throw new \InvalidArgumentException("Backup path does not exist: {$backupPath}");
        }

        try {
            $rootDir = $this->directoryList->getRoot();

            // Restore composer files
            if (file_exists($backupPath . '/composer.json')) {
                copy($backupPath . '/composer.json', $rootDir . '/composer.json');
            }
            if (file_exists($backupPath . '/composer.lock')) {
                copy($backupPath . '/composer.lock', $rootDir . '/composer.lock');
            }

            // Restore database
            if (file_exists($backupPath . '/database.sql.gz')) {
                $this->restoreDatabase($backupPath . '/database.sql.gz');
            }

            // Restore app/code
            if (file_exists($backupPath . '/app_code.tar.gz')) {
                $appCodeDir = $this->directoryList->getPath(DirectoryList::APP) . '/code';
                $this->restoreDirectory($backupPath . '/app_code.tar.gz', $appCodeDir);
            }

            // Restore app/etc
            if (file_exists($backupPath . '/app_etc.tar.gz')) {
                $appEtcDir = $this->directoryList->getPath(DirectoryList::APP) . '/etc';
                $this->restoreDirectory($backupPath . '/app_etc.tar.gz', $appEtcDir);
            }

            // Run composer install to restore vendor
            exec(sprintf('cd %s && composer install --no-interaction 2>&1', escapeshellarg($rootDir)));

            return true;
        } catch (\Exception $e) {
            $this->logger->error('AutoUpgrader restore failed: ' . $e->getMessage());
            return false;
        }
    }

    private function backupDatabase(string $outputFile): void
    {
        $deployConfig = $this->directoryList->getPath(DirectoryList::CONFIG) . '/env.php';
        $config = include $deployConfig;
        $dbConfig = $config['db']['connection']['default'] ?? [];

        $command = sprintf(
            'mysqldump -h %s -u %s %s %s | gzip > %s',
            escapeshellarg($dbConfig['host'] ?? 'localhost'),
            escapeshellarg($dbConfig['username'] ?? ''),
            !empty($dbConfig['password']) ? '-p' . escapeshellarg($dbConfig['password']) : '',
            escapeshellarg($dbConfig['dbname'] ?? ''),
            escapeshellarg($outputFile)
        );

        exec($command, $output, $returnCode);
        if ($returnCode !== 0) {
            throw new \RuntimeException('Database backup failed');
        }
    }

    private function restoreDatabase(string $backupFile): void
    {
        $deployConfig = $this->directoryList->getPath(DirectoryList::CONFIG) . '/env.php';
        $config = include $deployConfig;
        $dbConfig = $config['db']['connection']['default'] ?? [];

        $command = sprintf(
            'gunzip -c %s | mysql -h %s -u %s %s %s',
            escapeshellarg($backupFile),
            escapeshellarg($dbConfig['host'] ?? 'localhost'),
            escapeshellarg($dbConfig['username'] ?? ''),
            !empty($dbConfig['password']) ? '-p' . escapeshellarg($dbConfig['password']) : '',
            escapeshellarg($dbConfig['dbname'] ?? '')
        );

        exec($command, $output, $returnCode);
        if ($returnCode !== 0) {
            throw new \RuntimeException('Database restore failed');
        }
    }

    private function backupDirectory(string $sourceDir, string $outputFile): void
    {
        $parentDir = dirname($sourceDir);
        $dirName = basename($sourceDir);
        $command = sprintf(
            'tar -czf %s -C %s %s 2>&1',
            escapeshellarg($outputFile),
            escapeshellarg($parentDir),
            escapeshellarg($dirName)
        );
        exec($command, $output, $returnCode);
        if ($returnCode !== 0) {
            throw new \RuntimeException("Failed to backup directory: {$sourceDir}");
        }
    }

    private function restoreDirectory(string $archiveFile, string $targetDir): void
    {
        $parentDir = dirname($targetDir);
        $command = sprintf(
            'tar -xzf %s -C %s 2>&1',
            escapeshellarg($archiveFile),
            escapeshellarg($parentDir)
        );
        exec($command, $output, $returnCode);
        if ($returnCode !== 0) {
            throw new \RuntimeException("Failed to restore directory: {$targetDir}");
        }
    }

    private function getDirectorySize(string $path): int
    {
        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $size += $file->getSize();
        }
        return $size;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
