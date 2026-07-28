<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\InputBag;

final class DecodeStatisticsPayload
{
    private const MAX_COMPRESSED_BYTES = 8 * 1024 * 1024;
    private const MAX_DECOMPRESSED_BYTES = 64 * 1024 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->getContent();
        $encoding = strtolower(trim((string) $request->header('Content-Encoding', '')));
        if ($encoding !== '' && $encoding !== 'identity' && $encoding !== 'gzip') {
            return $this->error('unsupported_content_encoding', '仅支持 identity 或 gzip', 415);
        }

        if ($encoding === 'gzip') {
            if (strlen($raw) > self::MAX_COMPRESSED_BYTES) {
                return $this->error('compressed_payload_too_large', '压缩请求体超过 8 MiB', 413);
            }
            $decoded = @gzdecode($raw, self::MAX_DECOMPRESSED_BYTES + 1);
            if ($decoded === false) {
                return $this->error('invalid_gzip', 'gzip 请求体无法解压', 400);
            }
            if (strlen($decoded) > self::MAX_DECOMPRESSED_BYTES) {
                return $this->error('payload_too_large', '解压后的请求体超过 64 MiB', 413);
            }
            $raw = $decoded;
        } elseif (strlen($raw) > self::MAX_DECOMPRESSED_BYTES) {
            return $this->error('payload_too_large', '请求体超过 64 MiB', 413);
        }

        $expectedChecksum = strtolower(trim((string) $request->header('X-Statistics-Checksum', '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $expectedChecksum)) {
            return $this->error('missing_checksum', '缺少有效的 X-Statistics-Checksum', 400);
        }

        $actualChecksum = hash('sha256', $raw);
        if (!hash_equals($expectedChecksum, $actualChecksum)) {
            return $this->error('checksum_mismatch', '请求体校验和不匹配', 400);
        }

        try {
            $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->error('invalid_json', '请求体不是有效 JSON', 400);
        }

        if (!is_array($payload) || array_is_list($payload)) {
            return $this->error('invalid_envelope', '请求体必须是 JSON 对象', 422);
        }

        // Keep the protocol envelope separate from the compatibility fields
        // injected below for Xboard's existing server.v2 authentication.
        $request->attributes->set('statistics_payload', $payload);

        $token = trim((string) $request->header('X-Xboard-Token', ''));
        if ($token !== '') {
            $payload['token'] = $token;
        }
        foreach (['X-Xboard-Node-ID' => 'node_id', 'X-Xboard-Machine-ID' => 'machine_id'] as $header => $field) {
            $value = trim((string) $request->header($header, ''));
            if ($value === '') {
                continue;
            }
            if (!ctype_digit($value) || (int) $value <= 0) {
                return $this->error('invalid_auth_header', "{$header} 必须是正整数", 400);
            }
            $payload[$field] = (int) $value;
        }

        $request->request->replace($payload);
        $request->setJson(new InputBag($payload));
        $request->attributes->set('statistics_checksum', $actualChecksum);
        foreach ([
            'X-Statistics-Pending-Batches' => 'statistics_pending_batches',
            'X-Statistics-Retry-Attempt' => 'statistics_retry_attempt',
        ] as $header => $attribute) {
            $value = trim((string) $request->header($header, ''));
            if ($value === '') {
                continue;
            }
            if (!ctype_digit($value) || (int) $value > 1000000) {
                return $this->error('invalid_health_header', "{$header} 必须是 0-1000000 之间的整数", 400);
            }
            $request->attributes->set($attribute, (int) $value);
        }

        return $next($request);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'status' => 'fail',
            'message' => $message,
            'data' => null,
            'error' => ['code' => $code],
        ], $status);
    }
}
