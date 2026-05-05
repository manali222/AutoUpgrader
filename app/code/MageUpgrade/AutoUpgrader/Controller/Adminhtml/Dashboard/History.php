<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class History extends Action
{
    public const ADMIN_RESOURCE = 'MageUpgrade_AutoUpgrader::history';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('MageUpgrade_AutoUpgrader::history');
        $page->getConfig()->getTitle()->prepend(__('AutoUpgrader - Upgrade History'));
        return $page;
    }
}
