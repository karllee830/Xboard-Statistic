<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Controllers\Admin;

use App\Http\Controllers\PluginController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Plugin\XboardStatistic\Exceptions\QueryTimeoutException;
use Plugin\XboardStatistic\Services\Query\TrafficQueryService;
use Plugin\XboardStatistic\Support\ApiResponse;
use Plugin\XboardStatistic\Support\IpAddress;
use Plugin\XboardStatistic\Support\Settings;

final class TrafficController extends PluginController
{
    public function authCheck(): JsonResponse
    {
        return ApiResponse::success(['authenticated' => true]);
    }

    public function traffic(Request $request, TrafficQueryService $service): JsonResponse
    {
        return $this->respond(fn (): array => $this->maskSourceIps($service->query($request)));
    }

    public function overview(Request $request, TrafficQueryService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->overview($request));
    }

    public function health(Request $request, TrafficQueryService $service): JsonResponse
    {
        return $this->respond(fn (): array => $service->health($request));
    }

    private function maskSourceIps(array $result): array
    {
        $settings = Settings::fromGetter(fn (string $key, mixed $default): mixed => $this->getConfig($key, $default));
        if (!$settings->maskSourceIp() || ($result['group_by'] ?? null) !== 'source_ip') {
            return $result;
        }
        foreach ($result['items'] as &$item) {
            if (is_string($item['value'] ?? null) && !in_array($item['value'], ['unknown', '__other__'], true)) {
                $item['value'] = IpAddress::masked($item['value']);
                $item['label'] = $item['value'];
            }
        }
        unset($item);
        return $result;
    }

    /** @param callable(): array $callback */
    private function respond(callable $callback): JsonResponse
    {
        try {
            return ApiResponse::success($callback());
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::fail('invalid_query', $exception->getMessage(), 422);
        } catch (QueryTimeoutException $exception) {
            return ApiResponse::fail('query_timeout', $exception->getMessage(), 422);
        }
    }
}
