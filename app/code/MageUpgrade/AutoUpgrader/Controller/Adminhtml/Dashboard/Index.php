<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'MageUpgrade_AutoUpgrader::dashboard';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('MageUpgrade_AutoUpgrader::dashboard');
        $page->getConfig()->getTitle()->prepend(__('AutoUpgrader - Upgrade Dashboard'));
        return $page;
    }
}
