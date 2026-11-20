<?php

declare(strict_types=1);

namespace SR\Cloudflare\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use SR\Cloudflare\Model\Worker\AdminLocation;
use SR\Cloudflare\Model\Worker\RoutePlanner;

class DefaultWorkerRoutes extends Field
{
    protected $_template = 'SR_Cloudflare::system/config/default-worker-routes.phtml';

    public function __construct(
        Context $context,
        private readonly AdminLocation $adminLocation,
        SecureHtmlRenderer $secureRenderer,
        array $data = []
    ) {
        parent::__construct($context, $data, $secureRenderer);
    }

    /** @return list<string> */
    public function getDefaultPatterns(): array
    {
        return RoutePlanner::DEFAULT_BYPASS_PATTERNS;
    }

    public function getAdminPattern(): ?string
    {
        try {
            [$host, $path, $dedicated] = $this->adminLocation->resolve();
        } catch (LocalizedException) {
            return null;
        }

        return RoutePlanner::formatAdminBypassPattern($host, $path, $dedicated);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->toHtml();
    }
}
