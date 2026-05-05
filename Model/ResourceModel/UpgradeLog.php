<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class UpgradeLog extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('mageupgrade_upgrade_log', 'upgrade_id');
    }
}
