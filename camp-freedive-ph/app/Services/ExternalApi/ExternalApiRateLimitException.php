<?php

namespace App\Services\ExternalApi;

use Exception;

class ExternalApiRateLimitException extends Exception
{
    protected int $retryAfterSeconds;

    public function __construct(string $message = "External API rate limit reached.", int $code = 429, int $retryAfterSeconds = 60, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->retryAfterSeconds = $retryAfterSeconds;
    }

    public function getRetryAfter(): int
    {
        return $this->retryAfterSeconds;
    }
}
