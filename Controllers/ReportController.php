<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugin\XboardStatistic\Exceptions\IngestException;
use Plugin\XboardStatistic\Services\ReportIngestService;
use Plugin\XboardStatistic\Support\ApiResponse;
use Plugin\XboardStatistic\Support\Settings;
use Throwable;

final class ReportController extends PluginController
{
    public function store(Request $request, ReportIngestService $service): JsonResponse
    {
        $node = $request->attributes->get('node_info');
        $nodeId = is_object($node) && isset($node->id) ? (int) $node->id : 0;
        if ($nodeId <= 0) {
            return ApiResponse::fail('missing_node_context', '节点认证未提供有效 node_info', 401);
        }

        $settings = Settings::fromGetter(fn (string $key, mixed $default): mixed => $this->getConfig($key, $default));

        try {
            $result = $service->ingest(
                $nodeId,
                (string) $request->attributes->get('statistics_checksum', ''),
                (array) $request->attributes->get('statistics_payload', []),
                $settings->maxRecordsPerBatch(),
                $settings->maxReportAgeDays(),
                $request->attributes->get('statistics_pending_batches'),
                $request->attributes->get('statistics_retry_attempt'),
            );
            return ApiResponse::success($result, $result['result'] === 'duplicate' ? '批次已存在' : '批次已接收');
        } catch (IngestException $exception) {
            return ApiResponse::fail(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
                $exception->details,
            );
        } catch (Throwable $exception) {
            Log::error('Xboard Statistics report ingest failed', [
                'node_id' => $nodeId,
                'exception' => $exception,
            ]);
            return ApiResponse::fail('internal_error', '统计批次入库失败', 500);
        }
    }
}
