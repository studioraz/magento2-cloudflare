<?php

declare(strict_types=1);

namespace SR\Cloudflare\Controller\Adminhtml\Worker;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use SR\Cloudflare\Model\Worker\ForgetZone as ForgetDeletedZone;

class ForgetZone extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'SR_Cloudflare::srcloudflare_settings';

    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly ForgetDeletedZone $forgetZone
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        $accountId = $this->getRequest()->getParam('account_id');
        $zoneId = $this->getRequest()->getParam('zone_id');
        if (!is_string($accountId) || !is_string($zoneId) || $this->getRequest()->getParam('confirmed') !== '1') {
            return $result->setData(['error' => (string) __('Confirm the zone deletion before removing it from Magento.')]);
        }

        try {
            return $result->setData($this->forgetZone->execute($accountId, $zoneId));
        } catch (LocalizedException $exception) {
            return $result->setData(['error' => $exception->getMessage()]);
        }
    }
}
