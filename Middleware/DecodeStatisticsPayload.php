<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Middleware;

use Closure;
use Illuminate\Http\Request;
use JsonException;
use Plugin\XboardStatistic\Support\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decompresses and verifies an authenticated statistics report body.
 */
final class DecodeStatisticsPayload
{
    public const MAX_DECOMPRESSED_BYTES = 64 * 1024 * 1024;
    /** Deflate expands at most ~1032:1, so one chunk can never overshoot the limit by much. */
    private const INFLATE_CHUNK_BYTES = 16 * 1024;
    private const JSON_DEPTH = 16;

    public function handle(Request $request, Closure $next): Response
    {
        $encoding = strtolower(trim((string) $request->header('Content-Encoding', '')));
        if ($encoding !== '' && $encoding !== 'identity' && $encoding !== 'gzip') {
            return ApiResponse::fail('unsupported_content_encoding', '仅支持 identity 或 gzip', 415);
        }

        $raw = $request->getContent();
        if ($encoding === 'gzip') {
            $raw = $this->gunzip($raw);
            if ($raw === false) {
                return ApiResponse::fail('invalid_gzip', 'gzip 请求体无法解压', 400);
            }
            if ($raw === null) {
                return ApiResponse::fail('payload_too_large', '解压后的请求体超过 64 MiB', 413);
            }
        } elseif (strlen($raw) > self::MAX_DECOMPRESSED_BYTES) {
            return ApiResponse::fail('payload_too_large', '请求体超过 64 MiB', 413);
        }

        $expectedChecksum = strtolower(trim((string) $request->header('X-Statistics-Checksum', '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $expectedChecksum)) {
            return ApiResponse::fail('missing_checksum', '缺少有效的 X-Statistics-Checksum', 400);
        }
        $actualChecksum = hash('sha256', $raw);
        if (!hash_equals($expectedChecksum, $actualChecksum)) {
            return ApiResponse::fail('checksum_mismatch', '请求体校验和不匹配', 400);
        }

        try {
            $payload = json_decode($raw, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ApiResponse::fail('invalid_json', '请求体不是有效 JSON', 400);
        }
        unset($raw);
        if (!is_array($payload) || array_is_list($payload)) {
            return ApiResponse::fail('invalid_envelope', '请求体必须是 JSON 对象', 422);
        }

        foreach ([
            'X-Statistics-Pending-Batches' => 'statistics_pending_batches',
            'X-Statistics-Retry-Attempt' => 'statistics_retry_attempt',
        ] as $header => $attribute) {
            $value = trim((string) $request->header($header, ''));
            if ($value === '') {
                continue;
            }
            if (!ctype_digit($value) || (int) $value > 1000000) {
                return ApiResponse::fail('invalid_health_header', "{$header} 必须是 0-1000000 之间的整数", 400);
            }
            $request->attributes->set($attribute, (int) $value);
        }

        $request->attributes->set('statistics_payload', $payload);
        $request->attributes->set('statistics_checksum', $actualChecksum);

        return $next($request);
    }

    /**
     * Streams the inflate so an oversized body is detected without allocating it.
     *
     * @return string|false|null decoded body, false for corrupt data, null when the size limit is exceeded
     */
    private function gunzip(string $compressed): string|false|null
    {
        $context = @inflate_init(ZLIB_ENCODING_GZIP);
        if ($context === false) {
            return false;
        }

        $decoded = '';
        $length = strlen($compressed);
        for ($offset = 0; $offset < $length; $offset += self::INFLATE_CHUNK_BYTES) {
            $chunk = @inflate_add($context, substr($compressed, $offset, self::INFLATE_CHUNK_BYTES), ZLIB_SYNC_FLUSH);
            if ($chunk === false) {
                return false;
            }
            $decoded .= $chunk;
            if (strlen($decoded) > self::MAX_DECOMPRESSED_BYTES) {
                return null;
            }
            if (inflate_get_status($context) === ZLIB_STREAM_END) {
                // The checksum covers the decoded body, so bytes after the gzip member are irrelevant.
                return $decoded;
            }
        }

        // Input ended before the gzip trailer: the body was truncated.
        return false;
    }
}
