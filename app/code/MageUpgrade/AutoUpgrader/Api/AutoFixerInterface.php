<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Api;

interface AutoFixerInterface
{
    /**
     * Apply auto-fixes for issues found in a scan
     *
     * @param int $scanId
     * @param int[] $issueIds Specific issues to fix, or empty for all auto-fixable
     * @return mixed[] Array with 'fixed_count', 'failed_count', 'details'
     */
    public function applyFixes(int $scanId, array $issueIds = []): array;

    /**
     * Preview what a fix would change (dry-run)
     *
     * @param int $issueId
     * @return mixed[] Array with 'original', 'proposed', 'diff'
     */
    public function previewFix(int $issueId): array;
}
