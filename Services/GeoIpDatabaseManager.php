<?php

declare(strict_types=1);

namespace Plugin\XboardStatistic\Services;

use DateTimeImmutable;
use DateTimeZone;
use MaxMind\Db\Reader;
use Plugin\XboardStatistic\Support\PluginInfo;
use RuntimeException;
use Throwable;

final class GeoIpDatabaseManager
{
    private const DOWNLOAD_BASE = 'https://download.db-ip.com/free';
    private const CHECK_INTERVAL_SECONDS = 21600;
    private const MAX_COMPRESSED_BYTES = 33554432;
    private const MAX_DATABASE_BYTES = 67108864;

    public function __construct(private readonly ?string $directory = null)
    {
    }

    /**
     * @param callable(string): void|null $log
     * @return array{
     *   country_path: ?string,
     *   country_version: ?string,
     *   asn_path: ?string,
     *   asn_version: ?string,
     *   managed_directory: string
     * }
     */
    public function ensure(
        string $countryOverride = '',
        string $asnOverride = '',
        bool $force = false,
        ?callable $log = null,
    ): array {
        $this->loadReader();
        $directory = $this->managedDirectory();
        $this->createDirectory($directory);

        $lockPath = $directory . DIRECTORY_SEPARATOR . '.update.lock';
        $lock = fopen($lockPath, 'c+');
        if ($lock === false) {
            throw new RuntimeException("无法打开 GeoIP 更新锁：{$lockPath}");
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('无法获取 GeoIP 更新锁');
            }

            $statePath = $directory . DIRECTORY_SEPARATOR . 'state.json';
            $state = $this->readState($statePath);
            $result = ['managed_directory' => $directory];

            foreach ([
                'country' => trim($countryOverride),
                'asn' => trim($asnOverride),
            ] as $kind => $override) {
                if ($override !== '') {
                    try {
                        $this->validateDatabase($override, $kind);
                        $result[$kind . '_path'] = $override;
                        $result[$kind . '_version'] = $this->customVersion($override);
                        $this->log($log, "{$kind} 使用自定义 MMDB：{$override}");
                        continue;
                    } catch (Throwable $exception) {
                        $this->log($log, "{$kind} 自定义 MMDB 不可用，改用插件托管数据库：{$exception->getMessage()}");
                    }
                }

                [$path, $version, $kindState] = $this->ensureManagedKind(
                    $kind,
                    $directory,
                    is_array($state[$kind] ?? null) ? $state[$kind] : [],
                    $force,
                    $log,
                );
                $state[$kind] = $kindState;
                $result[$kind . '_path'] = $path;
                $result[$kind . '_version'] = $version;
            }

            $state['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
            $this->writeState($statePath, $state);
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function reader(string $path): Reader
    {
        $this->loadReader();
        return new Reader($path);
    }

    private function loadReader(): void
    {
        if (!class_exists(Reader::class)) {
            $autoload = dirname(__DIR__) . '/vendor/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
        }
        if (!class_exists(Reader::class)) {
            throw new RuntimeException('插件安装包缺少内置 MaxMind DB Reader');
        }
    }

    /**
     * @param array<string, mixed> $state
     * @param callable(string): void|null $log
     * @return array{0: ?string, 1: ?string, 2: array<string, mixed>}
     */
    private function ensureManagedKind(
        string $kind,
        string $directory,
        array $state,
        bool $force,
        ?callable $log,
    ): array {
        $path = $directory . DIRECTORY_SEPARATOR . "dbip-{$kind}-lite.mmdb";
        $release = is_string($state['release'] ?? null) ? $state['release'] : null;
        $lastAttempt = (int) ($state['last_attempt_unix'] ?? 0);
        $currentRelease = gmdate('Y-m');
        $hasValidCurrent = is_file($path) && is_readable($path) && $release === $currentRelease;
        $shouldAttempt = $force
            || !is_file($path)
            || (!$hasValidCurrent && time() - $lastAttempt >= self::CHECK_INTERVAL_SECONDS);

        if ($shouldAttempt) {
            $state['last_attempt_unix'] = time();
            $lastError = null;
            foreach ($this->releaseCandidates() as $candidate) {
                if (!$force && is_file($path) && $release === $candidate) {
                    break;
                }
                try {
                    $metadata = $this->downloadRelease($kind, $candidate, $path);
                    $release = $candidate;
                    $state = array_merge($state, $metadata, [
                        'release' => $candidate,
                        'last_success_unix' => time(),
                    ]);
                    $this->log($log, "已更新 {$kind} MMDB 到 {$candidate}");
                    $lastError = null;
                    break;
                } catch (Throwable $exception) {
                    $lastError = $exception;
                }
            }
            if ($lastError !== null) {
                $state['last_error'] = mb_substr($lastError->getMessage(), 0, 500);
                $this->log($log, "{$kind} MMDB 更新失败，保留现有数据库：{$lastError->getMessage()}");
            } else {
                unset($state['last_error']);
            }
        }

        try {
            $this->validateDatabase($path, $kind);
            return [$path, $release === null ? $this->customVersion($path) : 'dbip-' . $release, $state];
        } catch (Throwable $exception) {
            $this->log($log, "{$kind} MMDB 当前不可用：{$exception->getMessage()}");
            return [null, null, $state];
        }
    }

    /**
     * @return array{url: string, sha256: string, bytes: int, database_type: string}
     */
    private function downloadRelease(string $kind, string $release, string $target): array
    {
        $url = self::DOWNLOAD_BASE . "/dbip-{$kind}-lite-{$release}.mmdb.gz";
        $suffix = bin2hex(random_bytes(8));
        $compressed = $target . ".{$suffix}.gz.part";
        $database = $target . ".{$suffix}.part";

        try {
            $this->download($url, $compressed);
            $this->inflate($compressed, $database);
            $databaseType = $this->validateDatabase($database, $kind);
            $sha256 = hash_file('sha256', $database);
            if ($sha256 === false) {
                throw new RuntimeException('无法计算下载数据库的 SHA-256');
            }
            $bytes = filesize($database);
            if ($bytes === false) {
                throw new RuntimeException('无法读取下载数据库的大小');
            }
            if (!rename($database, $target)) {
                throw new RuntimeException("无法原子替换 GeoIP 数据库：{$target}");
            }
            return [
                'url' => $url,
                'sha256' => $sha256,
                'bytes' => $bytes,
                'database_type' => $databaseType,
            ];
        } finally {
            if (is_file($compressed)) {
                unlink($compressed);
            }
            if (is_file($database)) {
                unlink($database);
            }
        }
    }

    private function download(string $url, string $destination): void
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('PHP 缺少 curl 扩展，无法自动下载 GeoIP 数据库');
        }
        $output = fopen($destination, 'xb');
        if ($output === false) {
            throw new RuntimeException("无法创建下载临时文件：{$destination}");
        }

        $downloaded = 0;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_FAILONERROR => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Xboard-Statistic-GeoIP/' . PluginInfo::version(),
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use ($output, &$downloaded): int {
                $downloaded += strlen($data);
                if ($downloaded > self::MAX_COMPRESSED_BYTES) {
                    return 0;
                }
                $written = fwrite($output, $data);
                return $written === false ? 0 : $written;
            },
        ]);

        try {
            if (curl_exec($curl) !== true) {
                throw new RuntimeException('下载失败：' . curl_error($curl));
            }
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException("下载返回 HTTP {$status}");
            }
        } finally {
            curl_close($curl);
            fclose($output);
        }
    }

    private function inflate(string $compressed, string $destination): void
    {
        $input = gzopen($compressed, 'rb');
        $output = fopen($destination, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                gzclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('无法打开 GeoIP 解压临时文件');
        }

        $bytes = 0;
        try {
            while (!gzeof($input)) {
                $chunk = gzread($input, 1048576);
                if ($chunk === false) {
                    throw new RuntimeException('GeoIP gzip 数据损坏');
                }
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_DATABASE_BYTES) {
                    throw new RuntimeException('GeoIP 解压结果超过安全上限');
                }
                if ($chunk !== '' && fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('写入 GeoIP 数据库失败');
                }
            }
        } finally {
            gzclose($input);
            fclose($output);
        }
        if ($bytes < 1048576) {
            throw new RuntimeException('GeoIP 数据库体积异常');
        }
    }

    private function validateDatabase(string $path, string $kind): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException("MMDB 文件不存在或不可读：{$path}");
        }
        $reader = new Reader($path);
        try {
            $databaseType = (string) $reader->metadata()->databaseType;
        } finally {
            $reader->close();
        }
        $expected = $kind === 'country' ? 'country' : 'asn';
        if (stripos($databaseType, $expected) === false) {
            throw new RuntimeException("MMDB 类型 {$databaseType} 与 {$kind} 不匹配");
        }
        return $databaseType;
    }

    /** @return list<string> */
    private function releaseCandidates(): array
    {
        $current = new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('UTC'));
        return [
            $current->format('Y-m'),
            $current->modify('-1 month')->format('Y-m'),
        ];
    }

    private function customVersion(string $path): string
    {
        return 'custom-' . substr(hash('sha256', $path . '|' . filemtime($path) . '|' . filesize($path)), 0, 16);
    }

    private function managedDirectory(): string
    {
        if ($this->directory !== null && $this->directory !== '') {
            return rtrim($this->directory, DIRECTORY_SEPARATOR);
        }
        $configured = trim((string) getenv('XBOARD_STATISTIC_GEOIP_DIR'));
        if ($configured !== '') {
            return rtrim($configured, DIRECTORY_SEPARATOR);
        }
        if (function_exists('base_path')) {
            $persistentRoot = base_path('.docker/.data');
            if (is_dir($persistentRoot)) {
                return $persistentRoot . '/xboard-statistic/geoip';
            }
        }
        if (function_exists('storage_path')) {
            return storage_path('app/xboard-statistic/geoip');
        }
        return dirname(__DIR__) . '/storage/geoip';
    }

    private function createDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("无法创建 GeoIP 持久化目录：{$directory}");
        }
        if (!is_writable($directory)) {
            throw new RuntimeException("GeoIP 持久化目录不可写：{$directory}");
        }
    }

    /** @param callable(string): void|null $log */
    private function log(?callable $log, string $message): void
    {
        if ($log !== null) {
            $log($message);
        }
    }

    /** @return array<string, mixed> */
    private function readState(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $state */
    private function writeState(string $path, array $state): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.part';
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false || !rename($temporary, $path)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw new RuntimeException("无法写入 GeoIP 状态文件：{$path}");
        }
    }
}
