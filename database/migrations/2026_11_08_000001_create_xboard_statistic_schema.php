<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Plugin\XboardStatistic\Support\MinutePartitions;
use Plugin\XboardStatistic\Support\MySqlRequirement;
use Plugin\XboardStatistic\Support\Tables;

return new class extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    private const ASCII = 'CHARACTER SET ascii COLLATE ascii_bin';

    public function up(): void
    {
        MySqlRequirement::assert();

        $ascii = self::ASCII;
        $options = self::TABLE_OPTIONS;

        DB::statement("CREATE TABLE " . Tables::BATCHES . " (
            bucket_start_unix INT UNSIGNED NOT NULL,
            node_id INT UNSIGNED NOT NULL,
            batch_id CHAR(36) {$ascii} NOT NULL,
            checksum CHAR(64) {$ascii} NOT NULL,
            record_count INT UNSIGNED NOT NULL,
            stored_dimensions INT UNSIGNED NOT NULL,
            node_version VARCHAR(64) NULL,
            collector_version VARCHAR(64) NULL,
            collapsed_dimensions BIGINT UNSIGNED NOT NULL DEFAULT 0,
            collapsed_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            unknown_domain_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            unknown_destination_ip_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            reported_pending_batches INT UNSIGNED NULL,
            reported_retry_attempt INT UNSIGNED NULL,
            received_at_unix INT UNSIGNED NOT NULL,
            updated_at_unix INT UNSIGNED NOT NULL,
            PRIMARY KEY (bucket_start_unix, node_id),
            UNIQUE KEY xbs_batches_node_batch_unique (node_id, batch_id),
            KEY xbs_batches_node_bucket_index (node_id, bucket_start_unix),
            KEY xbs_batches_received_index (received_at_unix)
        ) {$options}");

        DB::statement("CREATE TABLE " . Tables::DOMAINS . " (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            exact_domain VARCHAR(253) {$ascii} NOT NULL,
            registrable_domain VARCHAR(253) {$ascii} NULL,
            last_seen_unix INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY xbs_domains_exact_unique (exact_domain),
            KEY xbs_domains_registrable_index (registrable_domain),
            KEY xbs_domains_last_seen_index (last_seen_unix)
        ) {$options}");

        DB::statement("CREATE TABLE " . Tables::IP_ADDRESSES . " (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            address BINARY(16) NOT NULL,
            country_code CHAR(2) {$ascii} NULL,
            asn INT UNSIGNED NULL,
            asn_organization VARCHAR(255) NULL,
            geo_version VARCHAR(80) {$ascii} NULL,
            last_seen_unix INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY xbs_ip_addresses_address_unique (address),
            KEY xbs_ip_addresses_asn_index (asn),
            KEY xbs_ip_addresses_geo_version_index (geo_version),
            KEY xbs_ip_addresses_last_seen_index (last_seen_unix)
        ) {$options}");

        DB::statement("CREATE TABLE " . Tables::OUTBOUNDS . " (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            tag VARCHAR(64) {$ascii} NOT NULL,
            type VARCHAR(64) {$ascii} NOT NULL,
            last_seen_unix INT UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY xbs_outbounds_tag_type_unique (tag, type),
            KEY xbs_outbounds_last_seen_index (last_seen_unix)
        ) {$options}");

        DB::statement("CREATE TABLE " . Tables::MINUTE . " (
            bucket_start_unix INT UNSIGNED NOT NULL,
            node_id INT UNSIGNED NOT NULL,
            dimension_hash BINARY(16) NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            source_ip_id INT UNSIGNED NULL,
            source_ip_state TINYINT UNSIGNED NOT NULL,
            destination_ip_id INT UNSIGNED NULL,
            destination_ip_state TINYINT UNSIGNED NOT NULL,
            domain_id INT UNSIGNED NULL,
            network VARCHAR(3) {$ascii} NOT NULL,
            application_protocol VARCHAR(32) {$ascii} NOT NULL,
            inbound_type VARCHAR(64) {$ascii} NOT NULL,
            outbound_id INT UNSIGNED NULL,
            destination_port SMALLINT UNSIGNED NULL,
            upload_bytes BIGINT UNSIGNED NOT NULL,
            download_bytes BIGINT UNSIGNED NOT NULL,
            connection_count BIGINT UNSIGNED NOT NULL,
            active_seconds BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (bucket_start_unix, node_id, dimension_hash),
            KEY xbs_minute_node_index (node_id, bucket_start_unix),
            KEY xbs_minute_user_index (user_id, bucket_start_unix),
            KEY xbs_minute_domain_index (domain_id, bucket_start_unix),
            KEY xbs_minute_source_ip_index (source_ip_id, bucket_start_unix),
            KEY xbs_minute_destination_ip_index (destination_ip_id, bucket_start_unix)
        ) {$options} " . MinutePartitions::initialClause(time()));

        $metrics = 'upload_bytes BIGINT UNSIGNED NOT NULL,
            download_bytes BIGINT UNSIGNED NOT NULL,
            connection_count BIGINT UNSIGNED NOT NULL,
            active_seconds BIGINT UNSIGNED NOT NULL';

        DB::statement("CREATE TABLE " . Tables::HOUR_NODE_USER . " (
            hour_unix INT UNSIGNED NOT NULL,
            node_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            {$metrics},
            PRIMARY KEY (hour_unix, node_id, user_id),
            KEY xbs_hour_nu_node_index (node_id, hour_unix),
            KEY xbs_hour_nu_user_index (user_id, hour_unix)
        ) {$options}");

        DB::statement("CREATE TABLE " . Tables::HOUR_NODE_USER_DOMAIN . " (
            hour_unix INT UNSIGNED NOT NULL,
            node_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            domain_id INT UNSIGNED NOT NULL,
            {$metrics},
            PRIMARY KEY (hour_unix, node_id, user_id, domain_id),
            KEY xbs_hour_nud_node_index (node_id, hour_unix),
            KEY xbs_hour_nud_user_index (user_id, hour_unix),
            KEY xbs_hour_nud_domain_index (domain_id, hour_unix)
        ) {$options}");
    }

    public function down(): void
    {
        foreach ([
            Tables::HOUR_NODE_USER_DOMAIN,
            Tables::HOUR_NODE_USER,
            Tables::MINUTE,
            Tables::OUTBOUNDS,
            Tables::IP_ADDRESSES,
            Tables::DOMAINS,
            Tables::BATCHES,
        ] as $table) {
            DB::statement("DROP TABLE IF EXISTS {$table}");
        }
    }
};
