<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ScanResult extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('mageupgrade_scan_result', 'scan_id');
    }
}
