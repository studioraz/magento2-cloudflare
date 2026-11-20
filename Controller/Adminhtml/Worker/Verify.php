<?php

declare(strict_types=1);

namespace SR\Cloudflare\Controller\Adminhtml\Worker;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use SR\Cloudflare\Model\Worker\Credentials;
use SR\Cloudflare\Model\Worker\Deploy as DeployWorker;
use SR\Cloudflare\Model\ZoneDirectory;

class Verify extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'SR_Cloudflare::srcloudflare_settings';

    public function __construct(
        Action\Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Credentials $credentials,
        private readonly ZoneDirectory $zones,
        private readonly DeployWorker $deployWorker
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->jsonFactory->create();
        try {
            $zones = $this->credentials->verify();
            $verified = $this->credentials->canDeploy($zones);
            return $result->setData([
                'zones' => $zones,
                'unavailableZoneIds' => [],
                'canDeploy' => $this->deployWorker->canDeploy(),
                'message' => (string) ($verified
                    ? __('Cloudflare token and Cache Purge verified for the selected zones.')
                    : __('Cloudflare token verified. Select and save available zones, then test again before deploying.')),
            ]);
        } catch (LocalizedException $exception) {
            $zones = $this->zones->getSavedZones();
            return $result->setData([
                'error' => $exception->getMessage(),
                'zones' => $zones,
                'unavailableZoneIds' => $zones === null ? [] : $this->zones->getUnavailableZoneIds($zones),
            ]);
        }
    }
}
