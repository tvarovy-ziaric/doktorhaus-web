<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

use RuntimeException;

final class DiagnosticsIngestException extends RuntimeException
{
    /** @var string */
    private $ingestCode;

    /** @var array<string, mixed> */
    private $details;

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(string $ingestCode, string $message, array $details = [])
    {
        parent::__construct($message);
        $this->ingestCode = $ingestCode;
        $this->details = $details;
    }

    public function getIngestCode(): string
    {
        return $this->ingestCode;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }
}
