<?php

declare(strict_types=1);

namespace SR\Cloudflare\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class CloudflareApiException extends LocalizedException
{
    public function __construct(
        Phrase $phrase,
        int $status = 0,
        private readonly bool $rateLimited = false,
        private readonly ?int $retryAfter = null
    ) {
        parent::__construct($phrase, null, $status);
    }

    public function isRateLimited(): bool
    {
        return $this->rateLimited;
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
