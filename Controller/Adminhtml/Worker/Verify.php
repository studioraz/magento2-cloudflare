<?php

declare(strict_types=1);

namespace SR\Cloudflare\Controller\Adminhtml\Worker;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use SR\Cloudflare\Exception\CloudflareApiException;
use SR\Cloudflare\Model\CloudflareApi;
use SR\Cloudflare\Model\ZoneDirectory;

class Verify extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'SR_Cloudflare::srcloudflare_settings';

    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly CloudflareApi $api,
        private readonly ZoneDirectory $zones
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        try {
            $this->api->verifyToken();
            return $result->setData(['zones' => $this->zones->refresh()]);
        } catch (CloudflareApiException $exception) {
            return $result->setData(['error' => $exception->getMessage()]);
        }
    }
}
