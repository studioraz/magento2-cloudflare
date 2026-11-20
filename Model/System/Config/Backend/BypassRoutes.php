<?php

declare(strict_types=1);

namespace SR\Cloudflare\Model\System\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use SR\Cloudflare\Model\Worker\RoutePlanner;

class BypassRoutes extends Value
{
    protected function _afterLoad(): void
    {
        parent::_afterLoad();
        $value = $this->getValue();
        $rows = [];
        foreach (preg_split('/[,\r\n]+/', is_string($value) ? $value : '') as $path) {
            $path = trim($path);
            if ($path !== '') {
                $rows['_route_' . count($rows)] = ['path' => $path];
            }
        }

        $this->setValue($rows);
    }

    public function beforeSave()
    {
        $rows = $this->getValue();
        if (!is_array($rows)) {
            throw new LocalizedException(__('Enter one Worker bypass path per row.'));
        }
        unset($rows['__empty']);

        $paths = [];
        foreach ($rows as $row) {
            $path = $row['path'] ?? null;
            $path = is_string($path) ? trim($path) : '';
            if (!RoutePlanner::isValidBypassPath($path)) {
                throw new LocalizedException(__('Enter valid Worker bypass paths and keep the customer routes active.'));
            }
            $paths[] = $path;
        }

        $this->setValue(implode(',', $paths));
        return parent::beforeSave();
    }
}
