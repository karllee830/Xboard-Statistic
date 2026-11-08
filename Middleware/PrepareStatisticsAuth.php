<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Middleware;

use Closure;
use Illuminate\Http\Request;
use Plugin\XboardStatistic\Support\ApiResponse;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exposes the header-only node credentials as request input for Xboard's
 * server.v2 middleware, so authentication runs before the body is decoded.
 */
final class PrepareStatisticsAuth
{
    public const MAX_BODY_BYTES = 8 * 1024 * 1024;

    public function handle(Request $request, Closure $next): Response
    {
        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return ApiResponse::fail('payload_too_large', '请求体超过 8 MiB', 413);
        }

        $fields = [];
        $token = trim((string) $request->header('X-Xboard-Token', ''));
        if ($token !== '') {
            $fields['token'] = $token;
        }
        foreach (['X-Xboard-Node-ID' => 'node_id', 'X-Xboard-Machine-ID' => 'machine_id'] as $header => $field) {
            $value = trim((string) $request->header($header, ''));
            if ($value === '') {
                continue;
            }
            if (!ctype_digit($value) || (int) $value <= 0) {
                return ApiResponse::fail('invalid_auth_header', "{$header} 必须是正整数", 400);
            }
            $fields[$field] = (int) $value;
        }

        // Replace both input sources so Laravel never parses the raw (possibly gzip) body here.
        $request->request->replace($fields);
        $request->setJson(new InputBag($fields));

        return $next($request);
    }
}
