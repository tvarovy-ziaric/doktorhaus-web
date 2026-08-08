<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

use RuntimeException;
use Throwable;

final class DiagnosticsStorageException extends RuntimeException
{
    /** @var string */
    private $storageCode;

    public function __construct(string $storageCode, string $message, ?Throwable $previous = null)
    {
        $this->storageCode = $storageCode;
        parent::__construct($message, 0, $previous);
    }

    public function getStorageCode(): string
    {
        return $this->storageCode;
    }
}
