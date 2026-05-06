<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Controller\Adminhtml\Scan;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;

class SystemCheck extends Action
{
    public const ADMIN_RESOURCE = 'MageUpgrade_AutoUpgrader::scan';

    private const VERSION_PHP_REQUIREMENTS = [
        '2.4.8' => ['~8.3.0', '~8.4.0'],
        '2.4.7' => ['~8.2.0', '~8.3.0'],
        '2.4.6' => ['~8.1.0', '~8.2.0'],
    ];

    private const REQUIRED_EXTENSIONS = [
        'bcmath',
        'ctype',
        'curl',
        'dom',
        'gd',
        'hash',
        'iconv',
        'intl',
        'mbstring',
        'openssl',
        'pdo_mysql',
        'simplexml',
        'soap',
        'spl',
        'xsl',
        'zip',
        'sockets',
    ];

    private const MIN_DISK_SPACE_MB = 2048;

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $targetVersion = $this->getRequest()->getParam('target_version');

            if (empty($targetVersion)) {
                return $result->setData(['success' => false, 'message' => 'Target version is required']);
            }

            $checks = [];
            $allPassed = true;

            // PHP version check
            $phpCheck = $this->checkPhpVersion($targetVersion);
            $checks[] = $phpCheck;
            if (!$phpCheck['passed']) {
                $allPassed = false;
            }

            // PHP extensions check
            foreach (self::REQUIRED_EXTENSIONS as $ext) {
                $extCheck = $this->checkExtension($ext);
                $checks[] = $extCheck;
                if (!$extCheck['passed']) {
                    $allPassed = false;
                }
            }

            // Composer version check
            $composerCheck = $this->checkComposerVersion();
            $checks[] = $composerCheck;
            if (!$composerCheck['passed']) {
                $allPassed = false;
            }

            // Disk space check
            $diskCheck = $this->checkDiskSpace();
            $checks[] = $diskCheck;
            if (!$diskCheck['passed']) {
                $allPassed = false;
            }

            return $result->setData([
                'success' => true,
                'compatible' => $allPassed,
                'checks' => $checks,
            ]);
        } catch (\Exception $e) {
            return $result->setData([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function checkPhpVersion(string $targetVersion): array
    {
        $currentPhp = PHP_VERSION;
        $requiredConstraints = $this->getPhpRequirement($targetVersion);
        $requiredLabel = implode(' || ', $requiredConstraints);
        $passed = $this->phpVersionMatches($currentPhp, $requiredConstraints);

        return [
            'requirement' => 'PHP Version',
            'current' => $currentPhp,
            'required' => $requiredLabel,
            'passed' => $passed,
            'critical' => true,
        ];
    }

    private function getPhpRequirement(string $targetVersion): array
    {
        // Match against major.minor.patch prefix
        foreach (self::VERSION_PHP_REQUIREMENTS as $versionPrefix => $phpConstraints) {
            if (str_starts_with($targetVersion, $versionPrefix)) {
                return $phpConstraints;
            }
        }

        // Default: require PHP 8.2+ for unknown versions
        return ['~8.2.0', '~8.3.0'];
    }

    private function phpVersionMatches(string $phpVersion, array $constraints): bool
    {
        $major = (int) explode('.', $phpVersion)[0];
        $minor = (int) (explode('.', $phpVersion)[1] ?? 0);

        foreach ($constraints as $constraint) {
            // Parse ~X.Y.0 format
            if (preg_match('/^~(\d+)\.(\d+)\./', $constraint, $matches)) {
                $reqMajor = (int) $matches[1];
                $reqMinor = (int) $matches[2];
                if ($major === $reqMajor && $minor === $reqMinor) {
                    return true;
                }
            }
        }

        return false;
    }

    private function checkExtension(string $extension): array
    {
        $loaded = extension_loaded($extension);

        return [
            'requirement' => 'ext-' . $extension,
            'current' => $loaded ? 'Installed' : 'Missing',
            'required' => 'Required',
            'passed' => $loaded,
            'critical' => true,
        ];
    }

    private function checkComposerVersion(): array
    {
        $composerVersion = 'Not found';
        $passed = false;

        $output = [];
        $returnCode = 0;
        // phpcs:ignore Magento2.Security.InsecureFunction
        @exec('composer --version 2>/dev/null', $output, $returnCode);

        if ($returnCode === 0 && !empty($output[0])) {
            if (preg_match('/(\d+\.\d+\.\d+)/', $output[0], $matches)) {
                $composerVersion = $matches[1];
                $passed = version_compare($composerVersion, '2.2.0', '>=');
            }
        }

        return [
            'requirement' => 'Composer',
            'current' => $composerVersion,
            'required' => '>= 2.2.0',
            'passed' => $passed,
            'critical' => true,
        ];
    }

    private function checkDiskSpace(): array
    {
        $freeBytes = disk_free_space(BP);
        $freeMb = $freeBytes !== false ? round($freeBytes / 1024 / 1024) : 0;
        $passed = $freeMb >= self::MIN_DISK_SPACE_MB;

        return [
            'requirement' => 'Disk Space',
            'current' => $freeMb . ' MB free',
            'required' => '>= ' . self::MIN_DISK_SPACE_MB . ' MB',
            'passed' => $passed,
            'critical' => false,
        ];
    }
}
