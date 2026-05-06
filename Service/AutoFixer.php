<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Service;

use Magento\Framework\Serialize\Serializer\Json;
use MageUpgrade\AutoUpgrader\Api\AutoFixerInterface;
use MageUpgrade\AutoUpgrader\Api\CompatibilityScannerInterface;
use Psr\Log\LoggerInterface;

class AutoFixer implements AutoFixerInterface
{
    public function __construct(
        private readonly CompatibilityScannerInterface $scanner,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function applyFixes(int $scanId, array $issueIds = []): array
    {
        $scanResult = $this->scanner->getScanResults($scanId);
        $issues = $this->json->unserialize($scanResult->getIssuesJson() ?? '[]');

        $fixedCount = 0;
        $failedCount = 0;
        $details = [];

        foreach ($issues as $index => &$issue) {
            if (!($issue['is_auto_fixable'] ?? false)) {
                continue;
            }

            // If specific issue IDs provided, only fix those
            if (!empty($issueIds) && !in_array($index, $issueIds)) {
                continue;
            }

            try {
                $fixed = $this->fixIssue($issue);
                if ($fixed) {
                    $issue['is_fixed'] = true;
                    $fixedCount++;
                    $details[] = [
                        'file' => $issue['file_path'],
                        'status' => 'fixed',
                        'description' => $issue['description'],
                    ];
                } else {
                    $failedCount++;
                    $details[] = [
                        'file' => $issue['file_path'],
                        'status' => 'skipped',
                        'description' => $issue['description'],
                        'reason' => 'Could not safely apply fix',
                    ];
                }
            } catch (\Exception $e) {
                $failedCount++;
                $details[] = [
                    'file' => $issue['file_path'],
                    'status' => 'failed',
                    'description' => $issue['description'],
                    'reason' => $e->getMessage(),
                ];
                $this->logger->error('AutoFixer: Failed to fix issue', [
                    'file' => $issue['file_path'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        return [
            'fixed_count' => $fixedCount,
            'failed_count' => $failedCount,
            'details' => $details,
        ];
    }

    public function previewFix(int $issueId): array
    {
        // Preview implementation - shows diff before applying
        return [
            'original' => '',
            'proposed' => '',
            'diff' => 'Preview not available for this issue type',
        ];
    }

    private function fixIssue(array $issue): bool
    {
        $filePath = $issue['file_path'] ?? '';
        $category = $issue['category'] ?? '';

        if (empty($filePath)) {
            throw new \RuntimeException('No file path specified for issue');
        }

        if (!file_exists($filePath)) {
            throw new \RuntimeException("File not found: {$filePath}");
        }

        if (!is_writable($filePath)) {
            throw new \RuntimeException("File not writable: {$filePath}");
        }

        $content = file_get_contents($filePath);
        $originalContent = $content;

        switch ($category) {
            case 'deprecated_class':
                $content = $this->fixDeprecatedClass($content, $issue);
                break;

            case 'deprecated_method':
                $content = $this->fixDeprecatedMethod($content, $issue);
                break;

            case 'php_compatibility':
                $content = $this->fixPhpCompatibility($content, $issue);
                break;

            case 'composer_constraint':
                $this->fixComposerConstraint($filePath, $issue);
                return true;

            default:
                throw new \RuntimeException("Unknown fix category: {$category}");
        }

        if ($content === $originalContent) {
            $this->logger->info('AutoFixer: no changes made', [
                'file' => $filePath,
                'category' => $category,
                'old_value' => $issue['old_value'] ?? '',
                'new_value' => $issue['new_value'] ?? '',
            ]);
            return false;
        }

        // Create backup before modifying
        $backupPath = $filePath . '.autoupgrader.bak';
        copy($filePath, $backupPath);

        file_put_contents($filePath, $content);
        return true;
    }

    private function fixDeprecatedClass(string $content, array $issue): string
    {
        $oldValue = $issue['old_value'] ?? '';
        $newValue = $issue['new_value'] ?? '';

        if (empty($oldValue) || empty($newValue)) {
            return $content;
        }

        // Replace use statements
        $content = str_replace(
            "use {$oldValue};",
            "use {$newValue};",
            $content
        );

        // Replace inline class references
        $oldShort = substr($oldValue, strrpos($oldValue, '\\') + 1);
        $newShort = substr($newValue, strrpos($newValue, '\\') + 1);

        // Replace fully qualified references
        $content = str_replace('\\' . $oldValue, '\\' . $newValue, $content);

        return $content;
    }

    private function fixDeprecatedMethod(string $content, array $issue): string
    {
        $oldMethod = $issue['old_value'] ?? '';
        $newMethod = $issue['new_value'] ?? '';

        if (empty($oldMethod) || empty($newMethod)) {
            return $content;
        }

        // Replace ->getEntityId( with ->getId( etc.
        $content = preg_replace(
            '/->(' . preg_quote($oldMethod, '/') . ')\s*\(/',
            '->' . $newMethod . '(',
            $content
        );

        return $content;
    }

    private function fixPhpCompatibility(string $content, array $issue): string
    {
        $oldValue = $issue['old_value'] ?? '';
        $newValue = $issue['new_value'] ?? '';

        if ($oldValue === 'utf8_encode' && !empty($newValue)) {
            $content = preg_replace(
                '/utf8_encode\s*\(([^)]+)\)/',
                'mb_convert_encoding($1, "UTF-8", "ISO-8859-1")',
                $content
            );
        }

        if ($oldValue === 'utf8_decode' && !empty($newValue)) {
            $content = preg_replace(
                '/utf8_decode\s*\(([^)]+)\)/',
                'mb_convert_encoding($1, "ISO-8859-1", "UTF-8")',
                $content
            );
        }

        return $content;
    }

    private function fixComposerConstraint(string $filePath, array $issue): string
    {
        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if (isset($data['require'])) {
            foreach ($data['require'] as $package => &$constraint) {
                if (str_starts_with($package, 'magento/')) {
                    // Loosen constraint to use >= instead of exact
                    if (preg_match('/^(\d+\.\d+\.\d+)$/', $constraint, $m)) {
                        $constraint = '>=' . $m[1];
                    }
                }
            }
        }

        file_put_contents(
            $filePath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        return $content;
    }
}
