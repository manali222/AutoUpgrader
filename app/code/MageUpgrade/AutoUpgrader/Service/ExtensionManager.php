<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Service;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Composer\ComposerInformation;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Shell;
use MageUpgrade\AutoUpgrader\Api\ExtensionManagerInterface;
use Psr\Log\LoggerInterface;

class ExtensionManager implements ExtensionManagerInterface
{
    public function __construct(
        private readonly ModuleListInterface $moduleList,
        private readonly ComposerInformation $composerInformation,
        private readonly Curl $curl,
        private readonly Json $json,
        private readonly DirectoryList $directoryList,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getInstalledExtensions(): array
    {
        $extensions = [];
        $installedPackages = $this->composerInformation->getInstalledMagentoPackages();

        foreach ($installedPackages as $packageName => $packageData) {
            // Skip core Magento packages
            if (str_starts_with($packageName, 'magento/module-')
                || str_starts_with($packageName, 'magento/framework')
                || str_starts_with($packageName, 'magento/language-')
                || str_starts_with($packageName, 'magento/theme-')
            ) {
                continue;
            }

            if (($packageData['type'] ?? '') === 'magento2-module') {
                $extensions[] = [
                    'package_name' => $packageName,
                    'current_version' => $packageData['version'] ?? 'unknown',
                    'description' => $packageData['description'] ?? '',
                    'type' => $packageData['type'] ?? '',
                ];
            }
        }

        // Also include local app/code modules (non-composer)
        $modules = $this->moduleList->getAll();
        foreach ($modules as $moduleName => $moduleData) {
            if (!str_starts_with($moduleName, 'Magento_')) {
                $alreadyListed = false;
                foreach ($extensions as $ext) {
                    if (str_contains($ext['package_name'], strtolower(str_replace('_', '-', $moduleName)))) {
                        $alreadyListed = true;
                        break;
                    }
                }
                if (!$alreadyListed) {
                    $extensions[] = [
                        'package_name' => $moduleName,
                        'current_version' => $moduleData['setup_version'] ?? '0.0.0',
                        'description' => 'Local module (app/code)',
                        'type' => 'local-module',
                    ];
                }
            }
        }

        return $extensions;
    }

    public function findCompatibleVersions(string $targetVersion): array
    {
        $extensions = $this->getInstalledExtensions();
        $results = [];

        foreach ($extensions as $ext) {
            $packageName = $ext['package_name'];

            if ($ext['type'] === 'local-module') {
                $results[] = array_merge($ext, [
                    'compatible_version' => 'N/A (local module - manual check required)',
                    'status' => 'manual_check',
                    'action' => 'Review code for compatibility',
                ]);
                continue;
            }

            $compatible = $this->findCompatibleVersion($packageName, $targetVersion);
            $results[] = array_merge($ext, [
                'compatible_version' => $compatible['version'] ?? 'not_found',
                'status' => $compatible['status'],
                'action' => $compatible['action'],
            ]);
        }

        return $results;
    }

    public function upgradeExtension(string $packageName, string $targetVersion): bool
    {
        try {
            $composerHome = $this->directoryList->getRoot();
            $command = sprintf(
                'cd %s && composer require %s:%s --no-update 2>&1',
                escapeshellarg($composerHome),
                escapeshellarg($packageName),
                escapeshellarg($targetVersion)
            );

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                $this->logger->error('Failed to update extension', [
                    'package' => $packageName,
                    'version' => $targetVersion,
                    'output' => implode("\n", $output),
                ]);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Extension upgrade failed: ' . $e->getMessage());
            return false;
        }
    }

    private function findCompatibleVersion(string $packageName, string $targetMagentoVersion): array
    {
        try {
            $url = "https://repo.packagist.org/p2/{$packageName}.json";
            $this->curl->get($url);
            $response = $this->curl->getBody();
            $data = $this->json->unserialize($response);

            $packages = $data['packages'][$packageName] ?? [];

            // Find the latest version that's compatible with the target Magento version
            foreach ($packages as $pkg) {
                $requires = $pkg['require'] ?? [];
                $magentoFramework = $requires['magento/framework'] ?? null;

                if ($magentoFramework && $this->isConstraintCompatible($magentoFramework, $targetMagentoVersion)) {
                    return [
                        'version' => $pkg['version'],
                        'status' => 'compatible',
                        'action' => "Upgrade to {$pkg['version']}",
                    ];
                }
            }

            return [
                'version' => null,
                'status' => 'no_compatible_version',
                'action' => 'Contact vendor for compatible version or remove extension',
            ];
        } catch (\Exception $e) {
            return [
                'version' => null,
                'status' => 'check_failed',
                'action' => 'Manual check required - could not query package repository',
            ];
        }
    }

    private function isConstraintCompatible(string $constraint, string $targetVersion): bool
    {
        // Basic constraint checking - handles ^, ~, >=, || patterns
        $constraint = trim($constraint);
        $parts = preg_split('/\s*\|\|\s*/', $constraint);

        foreach ($parts as $part) {
            $part = trim($part);

            if (str_starts_with($part, '>=')) {
                $minVersion = trim(substr($part, 2));
                if (version_compare($targetVersion, $minVersion, '>=')) {
                    return true;
                }
            } elseif (str_starts_with($part, '^')) {
                $baseVersion = trim(substr($part, 1));
                $majorVersion = explode('.', $baseVersion)[0];
                if (version_compare($targetVersion, $baseVersion, '>=')
                    && version_compare($targetVersion, ($majorVersion + 1) . '.0.0', '<')
                ) {
                    return true;
                }
            } elseif (str_starts_with($part, '~')) {
                $baseVersion = trim(substr($part, 1));
                $vParts = explode('.', $baseVersion);
                $nextMinor = $vParts[0] . '.' . ((int) ($vParts[1] ?? 0) + 1) . '.0';
                if (version_compare($targetVersion, $baseVersion, '>=')
                    && version_compare($targetVersion, $nextMinor, '<')
                ) {
                    return true;
                }
            }
        }

        return false;
    }
}
