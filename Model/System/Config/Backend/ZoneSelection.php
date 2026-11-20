<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model\System\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;

class ZoneSelection extends Value
{
    public function beforeSave()
    {
        $selection = $this->getValue();
        if (!is_string($selection) || !preg_match('/^(?:all|none|[a-f0-9]{32}(?:,[a-f0-9]{32})*)$/D', $selection)) {
            throw new LocalizedException(__('Invalid Cloudflare zone selection. Reload the page and select the zones again.'));
        }

        return parent::beforeSave();
    }
}
