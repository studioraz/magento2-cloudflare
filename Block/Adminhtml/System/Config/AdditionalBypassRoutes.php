<?php

declare(strict_types=1);

namespace SR\Cloudflare\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;

class AdditionalBypassRoutes extends AbstractFieldArray
{
    protected function _prepareToRender(): void
    {
        $this->addColumn('path', [
            'label' => __('Path'),
            'class' => 'input-text required-entry',
        ]);
        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Route');
    }
}
