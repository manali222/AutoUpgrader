<?php

declare(strict_types=1);

namespace MageUpgrade\AutoUpgrader\Controller\Adminhtml\Scan;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'MageUpgrade_AutoUpgrader::scan';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('MageUpgrade_AutoUpgrader::scan');
        $page->getConfig()->getTitle()->prepend(__('AutoUpgrader - Compatibility Scan'));
        return $page;
    }
}
