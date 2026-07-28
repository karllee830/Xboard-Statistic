<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Controllers\Admin;

use App\Http\Controllers\PluginController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Plugin\XboardStatistic\Services\TrafficQueryService;
use Plugin\XboardStatistic\Support\IpAddress;

final class TrafficController extends PluginController
{
    public function authCheck(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => ['authenticated' => true],
        ]);
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

    public function ranking(Request $request, string $group, TrafficQueryService $service): JsonResponse
    {
        try {
            $request->query->set('group_by', $group);
            $result = $service->query($request);
            $result = $this->maskSourceIps($result);
            return response()->json([
                'data' => $result['items'],
                'total' => $result['total'],
                'grand_total_bytes' => $result['grand_total_bytes'],
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'data' => [],
                'total' => 0,
            ], 422);
        }
    }

    private function maskSourceIps(array $result): array
    {
        if (!(bool) $this->getConfig('mask_source_ip', false) || ($result['group_by'] ?? null) !== 'source_ip') {
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

    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json([
                'status' => 'success',
                'message' => 'success',
                'data' => $callback(),
                'error' => null,
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'fail',
                'message' => $exception->getMessage(),
                'data' => null,
                'error' => ['code' => 'invalid_query'],
            ], 422);
        }
    }
}
