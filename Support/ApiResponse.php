<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Support;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function success(mixed $data, string $message = 'success'): JsonResponse
    {
        return new JsonResponse([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
            'error' => null,
        ]);
    }

    /** @param array<string, mixed> $details */
    public static function fail(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return new JsonResponse([
            'status' => 'fail',
            'message' => $message,
            'data' => null,
            'error' => ['code' => $code] + $details,
        ], $status);
    }
}
