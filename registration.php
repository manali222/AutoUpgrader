<?php
/**
 * MageUpgrade AutoUpgrader - Automated Magento Upgrade Plugin
 *
 * @category  MageUpgrade
 * @package   MageUpgrade_AutoUpgrader
 */

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'MageUpgrade_AutoUpgrader',
    __DIR__
);
