<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Services\Query;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Plugin\XboardStatistic\Exceptions\QueryTimeoutException;

/**
 * Caps the run time of report SELECTs so an oversized query fails fast instead of hanging the page.
 */
final class QueryGuard
{
    public const DEFAULT_TIMEOUT_MS = 20000;
    private const ER_QUERY_TIMEOUT = 3024;

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function run(callable $callback, int $timeoutMs = self::DEFAULT_TIMEOUT_MS): mixed
    {
        $previous = (int) DB::selectOne('SELECT @@SESSION.max_execution_time AS value')->value;
        DB::statement('SET SESSION max_execution_time = ' . max(1, $timeoutMs));
        try {
            return $callback();
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === self::ER_QUERY_TIMEOUT) {
                throw new QueryTimeoutException('查询超时：范围过大，请缩短时间范围或先选择节点、用户', 0, $exception);
            }
            throw $exception;
        } finally {
            DB::statement('SET SESSION max_execution_time = ' . $previous);
        }
    }
}
