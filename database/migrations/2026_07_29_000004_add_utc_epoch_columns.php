<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('xbs_report_batches', 'bucket_start_unix')) {
            Schema::table('xbs_report_batches', function (Blueprint $table): void {
                $table->unsignedBigInteger('bucket_start_unix')->nullable()->after('bucket_start');
                $table->unsignedBigInteger('bucket_end_unix')->nullable()->after('bucket_end');
                $table->unsignedBigInteger('received_at_unix')->nullable()->after('received_at');
            });
        }
        if (!Schema::hasColumn('xbs_traffic_hourly', 'bucket_start_unix')) {
            Schema::table('xbs_traffic_hourly', function (Blueprint $table): void {
                $table->unsignedBigInteger('bucket_start_unix')->nullable()->after('bucket_start');
            });
        }

        $sourceTimezone = $this->applicationTimezone();
        $utc = new DateTimeZone('UTC');

        DB::transaction(function () use ($sourceTimezone, $utc): void {
            DB::table('xbs_report_batches')
                ->select(['id', 'bucket_start', 'bucket_end', 'received_at', 'created_at', 'updated_at'])
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($sourceTimezone, $utc): void {
                    foreach ($rows as $row) {
                        $bucketStart = new DateTimeImmutable((string) $row->bucket_start, $utc);
                        $bucketEnd = new DateTimeImmutable((string) $row->bucket_end, $utc);
                        $receivedAt = new DateTimeImmutable((string) $row->received_at, $sourceTimezone);
                        DB::table('xbs_report_batches')->where('id', $row->id)->update([
                            'bucket_start' => $bucketStart->setTimezone($utc)->format('Y-m-d H:i:s'),
                            'bucket_start_unix' => $bucketStart->getTimestamp(),
                            'bucket_end' => $bucketEnd->setTimezone($utc)->format('Y-m-d H:i:s'),
                            'bucket_end_unix' => $bucketEnd->getTimestamp(),
                            'received_at' => $receivedAt->setTimezone($utc)->format('Y-m-d H:i:s'),
                            'received_at_unix' => $receivedAt->getTimestamp(),
                            'created_at' => $this->normalizeTimestamp($row->created_at, $sourceTimezone, $utc),
                            'updated_at' => $this->normalizeTimestamp($row->updated_at, $sourceTimezone, $utc),
                        ]);
                    }
                });

            DB::table('xbs_traffic_hourly')
                ->select(['id', 'bucket_start', 'created_at', 'updated_at'])
                ->orderBy('id')
                ->chunkById(1000, function ($rows) use ($sourceTimezone, $utc): void {
                    foreach ($rows as $row) {
                        $bucketStart = new DateTimeImmutable((string) $row->bucket_start, $utc);
                        DB::table('xbs_traffic_hourly')->where('id', $row->id)->update([
                            'bucket_start' => $bucketStart->setTimezone($utc)->format('Y-m-d H:i:s'),
                            'bucket_start_unix' => $bucketStart->getTimestamp(),
                            'created_at' => $this->normalizeTimestamp($row->created_at, $sourceTimezone, $utc),
                            'updated_at' => $this->normalizeTimestamp($row->updated_at, $sourceTimezone, $utc),
                        ]);
                    }
                });

            foreach (['xbs_domains', 'xbs_ip_addresses', 'xbs_outbounds'] as $table) {
                $this->normalizeAuditTimestamps($table, $sourceTimezone, $utc);
            }
        }, 3);

        Schema::table('xbs_report_batches', function (Blueprint $table): void {
            $table->unique(['node_id', 'bucket_start_unix'], 'xbs_batches_node_bucket_unix_unique');
            $table->index(['bucket_start_unix', 'status'], 'xbs_batches_bucket_unix_status_index');
            $table->index(['node_id', 'received_at_unix'], 'xbs_batches_node_received_unix_index');
        });
        Schema::table('xbs_traffic_hourly', function (Blueprint $table): void {
            $table->index('bucket_start_unix', 'xbs_traffic_bucket_unix_index');
            $table->index(['node_id', 'bucket_start_unix'], 'xbs_traffic_node_bucket_unix_index');
            $table->index(['user_id', 'bucket_start_unix'], 'xbs_traffic_user_bucket_unix_index');
            $table->index(['domain_id', 'bucket_start_unix'], 'xbs_traffic_domain_bucket_unix_index');
            $table->index(['source_ip_id', 'bucket_start_unix'], 'xbs_traffic_source_ip_bucket_unix_index');
            $table->index(['destination_ip_id', 'bucket_start_unix'], 'xbs_traffic_destination_ip_bucket_unix_index');
            $table->index(['outbound_id', 'bucket_start_unix'], 'xbs_traffic_outbound_bucket_unix_index');
        });
    }

    public function down(): void
    {
        Schema::table('xbs_traffic_hourly', function (Blueprint $table): void {
            $table->dropIndex('xbs_traffic_bucket_unix_index');
            $table->dropIndex('xbs_traffic_node_bucket_unix_index');
            $table->dropIndex('xbs_traffic_user_bucket_unix_index');
            $table->dropIndex('xbs_traffic_domain_bucket_unix_index');
            $table->dropIndex('xbs_traffic_source_ip_bucket_unix_index');
            $table->dropIndex('xbs_traffic_destination_ip_bucket_unix_index');
            $table->dropIndex('xbs_traffic_outbound_bucket_unix_index');
            $table->dropColumn('bucket_start_unix');
        });
        Schema::table('xbs_report_batches', function (Blueprint $table): void {
            $table->dropUnique('xbs_batches_node_bucket_unix_unique');
            $table->dropIndex('xbs_batches_bucket_unix_status_index');
            $table->dropIndex('xbs_batches_node_received_unix_index');
            $table->dropColumn(['bucket_start_unix', 'bucket_end_unix', 'received_at_unix']);
        });
    }

    private function applicationTimezone(): DateTimeZone
    {
        try {
            return new DateTimeZone((string) config('app.timezone', 'UTC'));
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }

    private function normalizeAuditTimestamps(string $table, DateTimeZone $source, DateTimeZone $utc): void
    {
        DB::table($table)
            ->select(['id', 'created_at', 'updated_at'])
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use ($table, $source, $utc): void {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update([
                        'created_at' => $this->normalizeTimestamp($row->created_at, $source, $utc),
                        'updated_at' => $this->normalizeTimestamp($row->updated_at, $source, $utc),
                    ]);
                }
            });
    }

    private function normalizeTimestamp(mixed $value, DateTimeZone $source, DateTimeZone $utc): ?string
    {
        if ($value === null || (string) $value === '') {
            return null;
        }

        return (new DateTimeImmutable((string) $value, $source))
            ->setTimezone($utc)
            ->format('Y-m-d H:i:s');
    }
};
