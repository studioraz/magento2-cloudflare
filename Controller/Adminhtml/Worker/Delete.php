<?php

declare(strict_types=1);

namespace SR\Cloudflare\Controller\Adminhtml\Worker;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use SR\Cloudflare\Model\Worker\Delete as DeleteWorker;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'SR_Cloudflare::srcloudflare_settings';

    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly DeleteWorker $deleteWorker
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $accountId = $this->getRequest()->getParam('account_id');
        $worker = $this->getRequest()->getParam('worker_name');
        if (!is_string($accountId) || !is_string($worker)) {
            return $result->setData(['error' => (string) __('The Cloudflare account and Worker are required.')]);
        }

        try {
            return $result->setData($this->deleteWorker->execute($accountId, $worker));
        } catch (LocalizedException $exception) {
            return $result->setData(['error' => $exception->getMessage()]);
        }
    }
}
