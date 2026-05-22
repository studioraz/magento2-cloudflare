<?php

declare(strict_types=1);

namespace SR\Cloudflare\Exception;

class RetryablePurgeException extends PurgeException
{
    public function __construct(
        string $message = '',
        private readonly ?int $retryAfter = null,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
