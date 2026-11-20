<?php

declare(strict_types=1);

namespace SR\Cloudflare\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Worker extends Field
{
    protected $_template = 'SR_Cloudflare::system/config/worker.phtml';

    public function getZoneField(): AbstractElement
    {
        return $this->getData('zone_field');
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $this->setData('zone_field', $element);
        return $this->toHtml();
    }
}
