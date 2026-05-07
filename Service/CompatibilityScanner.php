<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Service;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\Serializer\Json;
use MageUpgrade\AutoUpgrader\Api\CompatibilityScannerInterface;
use MageUpgrade\AutoUpgrader\Api\Data\ScanResultInterface;
use MageUpgrade\AutoUpgrader\Api\VersionResolverInterface;
use MageUpgrade\AutoUpgrader\Model\ScanResult;
use MageUpgrade\AutoUpgrader\Model\ScanResultFactory;
use MageUpgrade\AutoUpgrader\Model\ResourceModel\ScanResult as ScanResultResource;
use Psr\Log\LoggerInterface;

class CompatibilityScanner implements CompatibilityScannerInterface
{
    /**
     * Map of deprecated/removed classes and their replacements across Magento versions
     */
    private const DEPRECATED_CLASSES = [
        '2.4.8' => [
            'Magento\Framework\Serialize\Serializer\Serialize' => 'Magento\Framework\Serialize\Serializer\Json',
            'Zend_Db' => 'Magento\Framework\DB',
            'Zend_Json' => 'Magento\Framework\Serialize\Serializer\Json',
            'Zend_Log' => 'Psr\Log\LoggerInterface',
            'Zend_Validate' => 'Magento\Framework\Validator',
            'Zend_Http_Client' => 'Magento\Framework\HTTP\Client\Curl',
        ],
    ];

    private const DEPRECATED_METHODS = [
        '2.4.8' => [
            'getEntityId' => ['replacement' => 'getId', 'context' => 'AbstractModel', 'auto_fixable' => true],
            'setEntityId' => ['replacement' => 'setId', 'context' => 'AbstractModel', 'auto_fixable' => true],
            'getResource' => ['replacement' => 'Use ResourceModel via DI', 'context' => 'AbstractModel', 'auto_fixable' => false],
        ],
    ];

    private const PHP_DEPRECATIONS = [
        '8.3' => [
            'utf8_encode' => 'mb_convert_encoding($string, "UTF-8", "ISO-8859-1")',
            'utf8_decode' => 'mb_convert_encoding($string, "ISO-8859-1", "UTF-8")',
        ],
        '8.4' => [
            'implode separator after array' => 'implode(separator, array) - order enforced',
        ],
    ];

    public function __construct(
        private readonly ScanResultFactory $scanResultFactory,
        private readonly ScanResultResource $scanResultResource,
        private readonly VersionResolverInterface $versionResolver,
        private readonly ModuleListInterface $moduleList,
        private readonly Filesystem $filesystem,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
        private readonly DirectoryList $directoryList
    ) {
    }

    public function runScan(string $targetVersion): ScanResultInterface
    {
        $currentVersion = $this->versionResolver->getCurrentVersion();

        /** @var ScanResult $scanResult */
        $scanResult = $this->scanResultFactory->create();
        $scanResult->setCurrentVersion($currentVersion);
        $scanResult->setTargetVersion($targetVersion);
        $scanResult->setStatus('running');
        $this->scanResultResource->save($scanResult);

        try {
            $issues = [];
            $impactedFiles = [];

            // 1. Scan custom modules for deprecated class usage
            $deprecatedClassIssues = $this->scanDeprecatedClasses($targetVersion);
            $issues = array_merge($issues, $deprecatedClassIssues);

            // 2. Scan for deprecated method calls
            $deprecatedMethodIssues = $this->scanDeprecatedMethods($targetVersion);
            $issues = array_merge($issues, $deprecatedMethodIssues);

            // 3. Scan PHP version compatibility
            $phpIssues = $this->scanPhpCompatibility();
            $issues = array_merge($issues, $phpIssues);

            // 4. Scan for preference/plugin conflicts
            $pluginIssues = $this->scanPluginConflicts($targetVersion);
            $issues = array_merge($issues, $pluginIssues);

            // 5. Scan composer.json for version constraints
            $composerIssues = $this->scanComposerConstraints($targetVersion);
            $issues = array_merge($issues, $composerIssues);

            // 6. Scan for template overrides that may break
            $templateIssues = $this->scanTemplateOverrides($targetVersion);
            $issues = array_merge($issues, $templateIssues);

            // Collect impacted files
            foreach ($issues as $issue) {
                if (!empty($issue['file_path'])) {
                    $impactedFiles[$issue['file_path']] = true;
                }
            }

            // Calculate stats
            $criticalCount = count(array_filter($issues, fn($i) => $i['severity'] === 'critical'));
            $warningCount = count(array_filter($issues, fn($i) => $i['severity'] === 'warning'));
            $autoFixable = count(array_filter($issues, fn($i) => $i['is_auto_fixable'] ?? false));

            $scanResult->setStatus('completed');
            $scanResult->setTotalIssues(count($issues));
            $scanResult->setCriticalIssues($criticalCount);
            $scanResult->setWarnings($warningCount);
            $scanResult->setAutoFixable($autoFixable);
            $scanResult->setIssuesJson($this->json->serialize($issues));
            $scanResult->setImpactedFilesJson($this->json->serialize(array_keys($impactedFiles)));
            $scanResult->setData('completed_at', date('Y-m-d H:i:s'));
        } catch (\Exception $e) {
            $scanResult->setStatus('failed');
            $this->logger->error('AutoUpgrader scan failed: ' . $e->getMessage());
        }

        $this->scanResultResource->save($scanResult);
        return $scanResult;
    }

    public function getScanResults(int $scanId): ScanResultInterface
    {
        /** @var ScanResult $scanResult */
        $scanResult = $this->scanResultFactory->create();
        $this->scanResultResource->load($scanResult, $scanId);

        if (!$scanResult->getScanId()) {
            throw new \Magento\Framework\Exception\NoSuchEntityException(
                __('Scan result with ID "%1" does not exist.', $scanId)
            );
        }

        return $scanResult;
    }

    private function scanDeprecatedClasses(string $targetVersion): array
    {
        $issues = [];
        $baseVersion = preg_replace('/-p\d+$/', '', $targetVersion);
        $deprecatedMap = self::DEPRECATED_CLASSES[$baseVersion] ?? [];

        if (empty($deprecatedMap)) {
            return $issues;
        }

        $customCodePath = $this->directoryList->getPath(DirectoryList::APP) . '/code';
        if (!is_dir($customCodePath)) {
            return $issues;
        }

        $phpFiles = $this->findPhpFiles($customCodePath);

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            foreach ($deprecatedMap as $deprecated => $replacement) {
                if (str_contains($content, $deprecated)) {
                    $lineNumber = $this->findLineNumber($content, $deprecated);
                    $issues[] = [
                        'severity' => 'critical',
                        'category' => 'deprecated_class',
                        'file_path' => $file,
                        'line_number' => $lineNumber,
                        'description' => "Uses deprecated class '{$deprecated}'",
                        'suggestion' => "Replace with '{$replacement}'",
                        'is_auto_fixable' => true,
                        'module_name' => $this->getModuleNameFromPath($file),
                        'old_value' => $deprecated,
                        'new_value' => $replacement,
                    ];
                }
            }
        }

        return $issues;
    }

    private function scanDeprecatedMethods(string $targetVersion): array
    {
        $issues = [];
        $baseVersion = preg_replace('/-p\d+$/', '', $targetVersion);
        $methods = self::DEPRECATED_METHODS[$baseVersion] ?? [];

        if (empty($methods)) {
            return $issues;
        }

        $customCodePath = $this->directoryList->getPath(DirectoryList::APP) . '/code';
        if (!is_dir($customCodePath)) {
            return $issues;
        }

        $phpFiles = $this->findPhpFiles($customCodePath);

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            foreach ($methods as $method => $info) {
                if (preg_match('/->(' . preg_quote($method, '/') . ')\s*\(/', $content)) {
                    $lineNumber = $this->findLineNumber($content, $method);
                    $canAutoFix = $info['auto_fixable'] ?? false;
                    $issues[] = [
                        'severity' => 'warning',
                        'category' => 'deprecated_method',
                        'file_path' => $file,
                        'line_number' => $lineNumber,
                        'description' => "Uses deprecated method '{$method}' ({$info['context']})",
                        'suggestion' => "Replace with '{$info['replacement']}'",
                        'is_auto_fixable' => $canAutoFix,
                        'module_name' => $this->getModuleNameFromPath($file),
                        'old_value' => $method,
                        'new_value' => $info['replacement'],
                    ];
                }
            }
        }

        return $issues;
    }

    private function scanPhpCompatibility(): array
    {
        $issues = [];
        $customCodePath = $this->directoryList->getPath(DirectoryList::APP) . '/code';
        if (!is_dir($customCodePath)) {
            return $issues;
        }

        $phpFiles = $this->findPhpFiles($customCodePath);

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);

            foreach (self::PHP_DEPRECATIONS as $phpVersion => $deprecations) {
                foreach ($deprecations as $func => $replacement) {
                    if (str_contains($content, $func)) {
                        $lineNumber = $this->findLineNumber($content, $func);
                        $issues[] = [
                            'severity' => 'error',
                            'category' => 'php_compatibility',
                            'file_path' => $file,
                            'line_number' => $lineNumber,
                            'description' => "PHP {$phpVersion}: '{$func}' is deprecated/changed",
                            'suggestion' => "Replace with: {$replacement}",
                            'is_auto_fixable' => true,
                            'module_name' => $this->getModuleNameFromPath($file),
                            'old_value' => $func,
                            'new_value' => $replacement,
                        ];
                    }
                }
            }
        }

        return $issues;
    }

    private function scanPluginConflicts(string $targetVersion): array
    {
        $issues = [];
        $customCodePath = $this->directoryList->getPath(DirectoryList::APP) . '/code';
        if (!is_dir($customCodePath)) {
            return $issues;
        }

        // Find all di.xml files
        $diFiles = $this->findFiles($customCodePath, 'di.xml');

        foreach ($diFiles as $diFile) {
            $content = file_get_contents($diFile);
            $xml = @simplexml_load_string($content);
            if (!$xml) {
                continue;
            }

            // Check for preferences (class overrides)
            foreach ($xml->xpath('//preference') ?? [] as $preference) {
                $forClass = (string) ($preference['for'] ?? '');
                if (str_starts_with($forClass, 'Magento\\')) {
                    $issues[] = [
                        'severity' => 'warning',
                        'category' => 'class_override',
                        'file_path' => $diFile,
                        'line_number' => 0,
                        'description' => "Overrides core class: {$forClass}",
                        'suggestion' => "Verify override is compatible with {$targetVersion}. Consider using plugins instead.",
                        'is_auto_fixable' => false,
                        'module_name' => $this->getModuleNameFromPath($diFile),
                    ];
                }
            }

            // Check for plugins on core classes
            foreach ($xml->xpath('//type/plugin') ?? [] as $plugin) {
                $parent = $plugin->xpath('..')[0] ?? null;
                $typeName = (string) ($parent['name'] ?? '');
                if (str_starts_with($typeName, 'Magento\\')) {
                    $issues[] = [
                        'severity' => 'info',
                        'category' => 'plugin_on_core',
                        'file_path' => $diFile,
                        'line_number' => 0,
                        'description' => "Plugin on core class: {$typeName}",
                        'suggestion' => "Verify plugin method signatures match {$targetVersion} core.",
                        'is_auto_fixable' => false,
                        'module_name' => $this->getModuleNameFromPath($diFile),
                    ];
                }
            }
        }

        return $issues;
    }

    private function scanComposerConstraints(string $targetVersion): array
    {
        $issues = [];
        $customCodePath = $this->directoryList->getPath(DirectoryList::APP) . '/code';
        if (!is_dir($customCodePath)) {
            return $issues;
        }

        $composerFiles = $this->findFiles($customCodePath, 'composer.json');

        foreach ($composerFiles as $file) {
            $content = file_get_contents($file);
            $composerData = $this->json->unserialize($content);

            $requires = $composerData['require'] ?? [];
            foreach ($requires as $package => $constraint) {
                if (str_starts_with($package, 'magento/')) {
                    // Check if constraint is too restrictive for target version
                    if (str_contains($constraint, '<') || preg_match('/^\d+\.\d+\.\d+$/', $constraint)) {
                        $issues[] = [
                            'severity' => 'error',
                            'category' => 'composer_constraint',
                            'file_path' => $file,
                            'line_number' => 0,
                            'description' => "Package '{$package}' has restrictive constraint: {$constraint}",
                            'suggestion' => "Update constraint to be compatible with {$targetVersion}",
                            'is_auto_fixable' => true,
                            'module_name' => $this->getModuleNameFromPath($file),
                        ];
                    }
                }
            }
        }

        return $issues;
    }

    private function scanTemplateOverrides(string $targetVersion): array
    {
        $issues = [];
        $themePath = $this->directoryList->getPath(DirectoryList::APP) . '/design';
        if (!is_dir($themePath)) {
            return $issues;
        }

        $templateFiles = $this->findFiles($themePath, '*.phtml');

        foreach ($templateFiles as $file) {
            // Detect if template overrides a core Magento template
            if (preg_match('/Magento_[A-Za-z]+\/templates\//', $file)) {
                $issues[] = [
                    'severity' => 'warning',
                    'category' => 'template_override',
                    'file_path' => $file,
                    'line_number' => 0,
                    'description' => "Template override may conflict with {$targetVersion} changes",
                    'suggestion' => "Compare with core template in {$targetVersion} for changes",
                    'is_auto_fixable' => false,
                    'module_name' => $this->getModuleNameFromPath($file),
                ];
            }
        }

        return $issues;
    }

    private function findPhpFiles(string $directory): array
    {
        return $this->findFiles($directory, '*.php');
    }

    private function findFiles(string $directory, string $pattern): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && fnmatch($pattern, $file->getFilename())) {
                // Skip our own module to avoid false positives from string literals
                if (str_contains($file->getPathname(), 'MageUpgrade/AutoUpgrader')) {
                    continue;
                }
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function findLineNumber(string $content, string $search): int
    {
        $pos = strpos($content, $search);
        if ($pos === false) {
            return 0;
        }
        return substr_count($content, "\n", 0, $pos) + 1;
    }

    private function getModuleNameFromPath(string $path): string
    {
        if (preg_match('/app\/code\/([^\/]+)\/([^\/]+)/', $path, $matches)) {
            return $matches[1] . '_' . $matches[2];
        }
        return 'unknown';
    }
}
