<?php
/*
 * Copyright © 2022 Studio Raz. All rights reserved.
 * See LICENCE file for license details.
 */

declare(strict_types=1);

namespace SR\Cloudflare\Config;

class ModuleState
{
    protected Config $config;
    private ?bool $forceActive;

    /**
     * @param Config $config
     * @param bool|null $forceActive
     */
    public function __construct(Config $config, bool $forceActive = null)
    {
        $this->config = $config;
        $this->forceActive = $forceActive;
    }

    /**
     * @param mixed|null $store
     * @return bool
     */
    public function isActive($store = null): bool
    {
        if ($this->forceActive !== null) {
            return (bool)$this->forceActive;
        }

        return (bool)$this->config->getActive($store);
    }
}
