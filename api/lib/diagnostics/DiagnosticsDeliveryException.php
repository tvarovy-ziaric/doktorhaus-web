<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

use RuntimeException;
use Throwable;

final class DiagnosticsDeliveryException extends RuntimeException
{
    public const INVALID_REQUEST = 'DELIVERY_INVALID_REQUEST';
    public const SESSION_REQUIRED = 'DELIVERY_SESSION_REQUIRED';
    public const PROJECTION = 'DELIVERY_PROJECTION';
    public const INTEGRITY = 'DELIVERY_INTEGRITY';
    public const MEDIA_NOT_FOUND = 'DELIVERY_MEDIA_NOT_FOUND';
    public const MEDIA_TYPE = 'DELIVERY_MEDIA_TYPE';
    public const RANGE = 'DELIVERY_RANGE';
    public const STREAM = 'DELIVERY_STREAM';
    public const AUDIT = 'DELIVERY_AUDIT';

    /** @var string */
    private $deliveryCode;

    public function __construct(string $deliveryCode, string $message, ?Throwable $previous = null)
    {
        $this->deliveryCode = $deliveryCode;
        parent::__construct($message, 0, $previous);
    }

    public function getDeliveryCode(): string
    {
        return $this->deliveryCode;
    }
}
