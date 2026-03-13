<?php

declare(strict_types=1);

namespace Pynarae\Tracking\Controller;

use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Pynarae\Tracking\Model\Config;

class Router implements RouterInterface
{
    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly Config $config
    ) {
    }

    public function match(RequestInterface $request)
    {
        if (!$this->config->isEnabled()) {
            return null;
        }

        $identifier = trim((string)$request->getPathInfo(), '/');

        if (
            $identifier === 'track-order'
            || $identifier === 'track-order/index'
            || $identifier === 'track-order/index/index'
        ) {
            $request->setModuleName('tracking')
                ->setControllerName('index')
                ->setActionName('index');

            return $this->actionFactory->create(Forward::class);
        }

        if (
            $identifier === 'track-order/lookup'
            || $identifier === 'track-order/lookup/index'
        ) {
            $request->setModuleName('tracking')
                ->setControllerName('lookup')
                ->setActionName('index');

            return $this->actionFactory->create(Forward::class);
        }

        if (
            $identifier === 'track-order/webhook'
            || $identifier === 'track-order/webhook/index'
        ) {
            $request->setModuleName('tracking')
                ->setControllerName('webhook')
                ->setActionName('index');

            return $this->actionFactory->create(Forward::class);
        }

        return null;
    }
}
