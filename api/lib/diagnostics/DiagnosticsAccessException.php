<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

use RuntimeException;
use Throwable;

final class DiagnosticsAccessException extends RuntimeException
{
    /** @var string */
    private $accessCode;

    /** @var int|null */
    private $retryAfter;

    public function __construct(
        string $accessCode,
        string $message,
        ?Throwable $previous = null,
        ?int $retryAfter = null
    ) {
        $this->accessCode = $accessCode;
        $this->retryAfter = $retryAfter;
        parent::__construct($message, 0, $previous);
    }

    public function getAccessCode(): string
    {
        return $this->accessCode;
    }

    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
