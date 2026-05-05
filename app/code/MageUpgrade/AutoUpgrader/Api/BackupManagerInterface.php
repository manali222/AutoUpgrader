<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Api;

interface BackupManagerInterface
{
    /**
     * Create a full backup (database + files)
     *
     * @param string $label
     * @return mixed[] Array with 'backup_id', 'path', 'size'
     */
    public function createBackup(string $label = ''): array;

    /**
     * Restore from a backup
     *
     * @param string $backupPath
     * @return bool
     */
    public function restoreBackup(string $backupPath): bool;
}
