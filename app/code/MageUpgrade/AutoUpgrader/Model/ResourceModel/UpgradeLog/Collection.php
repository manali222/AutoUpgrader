<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Model\ResourceModel\UpgradeLog;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use MageUpgrade\AutoUpgrader\Model\UpgradeLog;
use MageUpgrade\AutoUpgrader\Model\ResourceModel\UpgradeLog as UpgradeLogResource;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'upgrade_id';

    protected function _construct(): void
    {
        $this->_init(UpgradeLog::class, UpgradeLogResource::class);
    }
}
