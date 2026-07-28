<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Controllers;

use App\Http\Controllers\PluginController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugin\XboardStatistic\Exceptions\IngestException;
use Plugin\XboardStatistic\Services\ReportIngestService;
use Throwable;

final class ReportController extends PluginController
{
    public function store(Request $request, ReportIngestService $service): JsonResponse
    {
        $node = $request->attributes->get('node_info');
        $nodeId = is_object($node) && isset($node->id) ? (int) $node->id : 0;
        if ($nodeId <= 0) {
            return $this->error('missing_node_context', '节点认证未提供有效 node_info', 401);
        }

        $checksum = (string) $request->attributes->get('statistics_checksum', '');
        $maxRecords = max(1, min(200000, (int) $this->getConfig('max_records_per_batch', 200000)));
        $maxAgeDays = max(1, min(90, (int) $this->getConfig('max_report_age_days', 14)));

        try {
            $result = $service->ingest(
                $nodeId,
                $checksum,
                (array) $request->attributes->get('statistics_payload', []),
                $maxRecords,
                $maxAgeDays,
                $request->attributes->get('statistics_pending_batches'),
                $request->attributes->get('statistics_retry_attempt'),
            );
            return response()->json([
                'status' => 'success',
                'message' => $result['result'] === 'duplicate' ? '批次已存在' : '批次已接收',
                'data' => $result,
                'error' => null,
            ]);
        } catch (IngestException $exception) {
            return $this->error(
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
            return $this->error('internal_error', '统计批次入库失败', 500);
        }
    }

    private function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return response()->json([
            'status' => 'fail',
            'message' => $message,
            'data' => null,
            'error' => ['code' => $code] + $details,
        ], $status);
    }
}
