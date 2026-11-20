<?php

declare(strict_types=1);

namespace SR\Cloudflare\Controller\Adminhtml\Worker;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use SR\Cloudflare\Config\CacheConfig;
use SR\Cloudflare\Exception\CloudflareApiException;
use SR\Cloudflare\Model\CloudflareApi;
use SR\Cloudflare\Model\Worker\Delete as DeleteWorker;
use SR\Cloudflare\Model\ZoneDirectory;

class Status extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'SR_Cloudflare::srcloudflare_settings';

    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly CacheConfig $config,
        private readonly CloudflareApi $api,
        private readonly ZoneDirectory $zones,
        private readonly DeleteWorker $deleteWorker
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        if ($this->config->getAccountId() === '' || !$this->config->getApiToken()) {
            return $result->setData(['error' => (string) __('Save the Cloudflare Account ID and API token first.')]);
        }

        $data = ['zones' => $this->zones->getSavedZones(), 'canDelete' => $this->deleteWorker->canDelete()];
        try {
            $data['deployment'] = $this->api->getActiveDeployment();
        } catch (CloudflareApiException $exception) {
            $data['error'] = $exception->getMessage();
        }
        return $result->setData($data);
    }
}
