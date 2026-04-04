<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Controller\Webhook;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\ForwardFactory;

class Index extends Action
{
    public function __construct(Context $context, private readonly ForwardFactory $resultForwardFactory)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $forward = $this->resultForwardFactory->create();
        return $forward->setController('webhook')->forward('track123');
    }
}
