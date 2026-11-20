<?php

declare(strict_types=1);

namespace SR\Cloudflare\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use SR\Cloudflare\Model\Worker\CacheEngine;

class CurrentCacheEngine extends Field
{
    public function __construct(
        Context $context,
        private readonly CacheEngine $cacheEngine,
        SecureHtmlRenderer $secureRenderer,
        array $data = []
    ) {
        parent::__construct($context, $data, $secureRenderer);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $element->setValue($this->cacheEngine->getLabel());
        $element->setBold(true);
        $element->setAfterElementHtml('');

        return parent::_getElementHtml($element);
    }

    protected function _isInheritCheckboxRequired(AbstractElement $element): bool
    {
        return false;
    }
}
