<?php
declare(strict_types=1);

namespace DoktorHaus\Diagnostics;

require_once __DIR__ . '/DiagnosticsDeliveryException.php';

final class DiagnosticsMediaDelivery
{
    private const CHUNK_SIZE = 65536;

    private const INLINE_MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'application/pdf' => 'pdf',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/ogg' => 'ogg',
    ];

    /**
     * @return array{status: int, start: int, end: int, length: int, partial: bool}
     */
    public function parseRange(?string $rangeHeader, int $size): array
    {
        if ($size < 0) {
            throw new DiagnosticsDeliveryException('DELIVERY_INTEGRITY', 'The media size is invalid.');
        }
        if ($rangeHeader === null || trim($rangeHeader) === '') {
            return [
                'status' => 200,
                'start' => 0,
                'end' => $size > 0 ? $size - 1 : 0,
                'length' => $size,
                'partial' => false,
            ];
        }

        $rangeHeader = trim($rangeHeader);
        if (strpos($rangeHeader, ',') !== false || preg_match('/^bytes=([^\s]+)$/D', $rangeHeader, $match) !== 1) {
            throw new DiagnosticsDeliveryException('DELIVERY_RANGE', 'The media byte range is invalid.');
        }
        if ($size === 0) {
            throw new DiagnosticsDeliveryException('DELIVERY_RANGE', 'The media byte range is unsatisfiable.');
        }

        $specification = $match[1];
        if (preg_match('/^([0-9]*)-([0-9]*)$/D', $specification, $parts) !== 1 ||
            ($parts[1] === '' && $parts[2] === '')) {
            throw new DiagnosticsDeliveryException('DELIVERY_RANGE', 'The media byte range is invalid.');
        }

        if ($parts[1] === '') {
            $suffixLength = $this->parseUnsignedInteger($parts[2]);
            if ($suffixLength < 1) {
                throw new DiagnosticsDeliveryException('DELIVERY_RANGE', 'The media byte range is unsatisfiable.');
            }
            $length = min($suffixLength, $size);
            $start = $size - $length;
            $end = $size - 1;
        } else {
            $start = $this->parseUnsignedInteger($parts[1]);
            if ($start >= $size) {
                throw new DiagnosticsDeliveryException('DELIVERY_RANGE', 'The media byte range is unsatisfiable.');
            }
            if ($parts[2] === '') {
                $end = $size - 1;
            } else {
                $requestedEnd = $this->parseUnsignedInteger($parts[2]);
                if ($requestedEnd < $start) {
                    throw new DiagnosticsDeliveryException('DELIVERY_RANGE', 'The media byte range is unsatisfiable.');
                }
                $end = min($requestedEnd, $size - 1);
            }
            $length = $end - $start + 1;
        }

        return [
            'status' => 206,
            'start' => $start,
            'end' => $end,
            'length' => $length,
            'partial' => true,
        ];
    }

    /** @return array{content_type: string, disposition: string, filename: string, inline: bool} */
    public function responseType(string $evidenceId, string $declaredContentType): array
    {
        $normalized = strtolower(trim(explode(';', $declaredContentType, 2)[0]));
        $inline = isset(self::INLINE_MIME_EXTENSIONS[$normalized]);
        $extension = $inline ? self::INLINE_MIME_EXTENSIONS[$normalized] : 'bin';
        $contentType = $inline ? $normalized : 'application/octet-stream';
        $filename = 'doktorhaus-' . $evidenceId . '.' . $extension;
        return [
            'content_type' => $contentType,
            'disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"',
            'filename' => $filename,
            'inline' => $inline,
        ];
    }

    /**
     * Stream an already-authorized regular file without loading it into memory.
     *
     * @param array{status: int, start: int, end: int, length: int, partial: bool} $range
     */
    public function stream(string $path, array $range, bool $headOnly): void
    {
        if ($headOnly || $range['length'] === 0) {
            return;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new DiagnosticsDeliveryException('DELIVERY_STREAM', 'The media stream cannot be opened.');
        }
        try {
            if ($range['start'] > 0 && fseek($handle, $range['start'], SEEK_SET) !== 0) {
                throw new DiagnosticsDeliveryException('DELIVERY_STREAM', 'The media stream cannot seek.');
            }
            $remaining = $range['length'];
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(self::CHUNK_SIZE, $remaining));
                if ($chunk === false) {
                    throw new DiagnosticsDeliveryException('DELIVERY_STREAM', 'The media stream cannot be read.');
                }
                if ($chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            if ($remaining !== 0) {
                throw new DiagnosticsDeliveryException('DELIVERY_STREAM', 'The media stream ended unexpectedly.');
            }
        } finally {
            fclose($handle);
        }
    }

    public static function discardOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) {
                break;
            }
        }
    }

    private function parseUnsignedInteger(string $value): int
    {
        if ($value === '' || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new DiagnosticsDeliveryException('DELIVERY_RANGE', 'The media byte range is invalid.');
        }
        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;
        $maximum = (string)PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum) ||
            (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            throw new DiagnosticsDeliveryException('DELIVERY_RANGE', 'The media byte range is invalid.');
        }
        return (int)$normalized;
    }
}
