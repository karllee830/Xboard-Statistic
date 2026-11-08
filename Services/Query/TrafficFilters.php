<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Services\Query;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Plugin\XboardStatistic\Support\IpAddress;
use Plugin\XboardStatistic\Support\UtcTime;
use Throwable;

/**
 * Validated time range and filter conditions of an admin traffic query.
 */
final class TrafficFilters
{
    public const MAX_SPAN_SECONDS = 730 * 86400;
    private const TOKEN_PATTERN = '/^(?:[a-z0-9][a-z0-9._-]*|__other__)$/';

    /**
     * @param array<string, mixed> $detail normalized filters that only the minute table can answer
     */
    public function __construct(
        public readonly int $from,
        public readonly int $to,
        public readonly ?int $nodeId = null,
        public readonly ?int $userId = null,
        public readonly ?string $domain = null,
        public readonly array $detail = [],
    ) {
        if ($to <= $from) {
            throw new InvalidArgumentException('to 必须晚于 from');
        }
        if ($to - $from > self::MAX_SPAN_SECONDS) {
            throw new InvalidArgumentException('查询时间范围不能超过 730 天');
        }
    }

    public static function fromRequest(Request $request, ?int $nowUnix = null): self
    {
        $now = $nowUnix ?? time();
        try {
            $from = $request->query->has('from') ? UtcTime::parseApi((string) $request->query('from'))->getTimestamp() : $now - 86400;
            $to = $request->query->has('to') ? UtcTime::parseApi((string) $request->query('to'))->getTimestamp() : $now;
        } catch (Throwable) {
            throw new InvalidArgumentException('from/to 必须是包含时区的 ISO 8601 时间');
        }

        $detail = [];
        foreach (['source_ip', 'destination_ip'] as $field) {
            if (self::present($request, $field)) {
                $detail[$field] = self::ipFilter($field, self::text($request, $field));
            }
        }
        foreach (['network', 'application_protocol', 'inbound_type', 'outbound'] as $field) {
            if (self::present($request, $field)) {
                $value = strtolower(self::text($request, $field));
                if (strlen($value) > 64 || !preg_match(self::TOKEN_PATTERN, $value)) {
                    throw new InvalidArgumentException("{$field} 包含非法字符");
                }
                $detail[$field] = $value;
            }
        }
        if (self::present($request, 'destination_port')) {
            $port = filter_var(self::text($request, 'destination_port'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0, 'max_range' => 65535],
            ]);
            if ($port === false) {
                throw new InvalidArgumentException('destination_port 必须在 0-65535 之间');
            }
            $detail['destination_port'] = $port;
        }
        foreach (['source_country', 'destination_country'] as $field) {
            if (self::present($request, $field)) {
                $value = strtoupper(self::text($request, $field));
                if (!preg_match('/^(?:[A-Z]{2}|UNKNOWN|__OTHER__)$/', $value)) {
                    throw new InvalidArgumentException("{$field} 必须是两位国家代码、unknown 或 __other__");
                }
                $detail[$field] = strlen($value) === 2 ? $value : strtolower($value);
            }
        }
        foreach (['source_asn', 'destination_asn'] as $field) {
            if (self::present($request, $field)) {
                $value = strtolower(self::text($request, $field));
                if ($value !== 'unknown' && $value !== '__other__' && (!ctype_digit($value) || (int) $value <= 0)) {
                    throw new InvalidArgumentException("{$field} 必须是正整数、unknown 或 __other__");
                }
                $detail[$field] = $value;
            }
        }

        $domain = null;
        if (self::present($request, 'domain')) {
            $domain = strtolower(rtrim(self::text($request, 'domain'), '.'));
            if (strlen($domain) > 253) {
                throw new InvalidArgumentException('domain 不能超过 253 字节');
            }
        }

        return new self(
            $from,
            $to,
            self::positiveId($request, 'node_id'),
            self::positiveId($request, 'user_id'),
            $domain,
            $detail,
        );
    }

    public function span(): int
    {
        return $this->to - $this->from;
    }

    public function hasDetailFilters(): bool
    {
        return $this->detail !== [];
    }

    public function needsGeo(string $side): bool
    {
        return isset($this->detail[$side . '_country']) || isset($this->detail[$side . '_asn']);
    }

    /** @return array{state: int}|array{packed: string} */
    private static function ipFilter(string $field, string $value): array
    {
        $value = strtolower($value);
        if ($value === 'unknown') {
            return ['state' => 0];
        }
        if ($value === '__other__') {
            return ['state' => 2];
        }
        try {
            $normalized = IpAddress::normalize($value);
        } catch (Throwable) {
            $normalized = null;
        }
        if ($normalized === null || $normalized['packed'] === null) {
            throw new InvalidArgumentException("{$field} 不是有效 IP 地址");
        }
        return ['packed' => $normalized['packed']];
    }

    private static function positiveId(Request $request, string $field): ?int
    {
        if (!self::present($request, $field)) {
            return null;
        }
        $value = filter_var(self::text($request, $field), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 4294967295],
        ]);
        if ($value === false) {
            throw new InvalidArgumentException("{$field} 必须是正整数");
        }
        return $value;
    }

    private static function present(Request $request, string $field): bool
    {
        return $request->query->has($field) && self::text($request, $field) !== '';
    }

    private static function text(Request $request, string $field): string
    {
        $value = $request->query($field);
        if (!is_scalar($value)) {
            throw new InvalidArgumentException("{$field} 必须是单个值");
        }
        return trim((string) $value);
    }
}
