<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xbs_report_batches', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('node_id');
            $table->uuid('batch_id');
            $table->unsignedSmallInteger('schema_version');
            $table->timestamp('bucket_start');
            $table->timestamp('bucket_end');
            $table->char('checksum', 64);
            $table->unsignedInteger('record_count');
            $table->string('status', 16)->default('accepted');
            $table->string('node_version', 64)->nullable();
            $table->string('collector_version', 64)->nullable();
            $table->json('quality')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            $table->unique(['node_id', 'batch_id'], 'xbs_batches_node_batch_unique');
            $table->unique(['node_id', 'bucket_start'], 'xbs_batches_node_bucket_unique');
            $table->index(['bucket_start', 'status'], 'xbs_batches_bucket_status_index');
        });

        Schema::create('xbs_domains', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('exact_domain', 253);
            $table->string('registrable_domain', 253)->nullable();
            $table->timestamps();

            $table->unique('exact_domain', 'xbs_domains_exact_unique');
            $table->index('registrable_domain', 'xbs_domains_registrable_index');
        });

        Schema::create('xbs_ip_addresses', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->binary('address', 16, true);
            $table->unsignedTinyInteger('ip_version');
            $table->string('country_code', 2)->nullable();
            $table->unsignedBigInteger('asn')->nullable();
            $table->string('asn_organization', 255)->nullable();
            $table->timestamps();

            $table->unique('address', 'xbs_ip_addresses_address_unique');
            $table->index(['country_code', 'asn'], 'xbs_ip_addresses_geo_index');
        });

        Schema::create('xbs_outbounds', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('tag', 64);
            $table->string('type', 64);
            $table->timestamps();

            $table->unique(['tag', 'type'], 'xbs_outbounds_tag_type_unique');
        });

        Schema::create('xbs_traffic_hourly', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('batch_id');
            $table->timestamp('bucket_start');
            $table->unsignedBigInteger('node_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('source_ip_id')->nullable();
            $table->unsignedTinyInteger('source_ip_state')->default(0);
            $table->unsignedBigInteger('destination_ip_id')->nullable();
            $table->unsignedTinyInteger('destination_ip_state')->default(0);
            $table->unsignedBigInteger('domain_id')->nullable();
            $table->string('network', 3);
            $table->string('application_protocol', 32)->default('unknown');
            $table->string('inbound_type', 64)->default('unknown');
            $table->unsignedBigInteger('outbound_id')->nullable();
            $table->unsignedSmallInteger('destination_port')->nullable();
            $table->unsignedBigInteger('upload_bytes')->default(0);
            $table->unsignedBigInteger('download_bytes')->default(0);
            $table->unsignedBigInteger('connection_count')->default(0);
            $table->unsignedBigInteger('active_seconds')->default(0);
            $table->char('dimension_hash', 64);
            $table->timestamps();

            $table->unique(
                ['bucket_start', 'node_id', 'dimension_hash'],
                'xbs_traffic_hour_dimension_unique'
            );
            $table->index(['node_id', 'bucket_start'], 'xbs_traffic_node_bucket_index');
            $table->index(['user_id', 'bucket_start'], 'xbs_traffic_user_bucket_index');
            $table->index(['domain_id', 'bucket_start'], 'xbs_traffic_domain_bucket_index');
            $table->index(['source_ip_id', 'bucket_start'], 'xbs_traffic_source_ip_bucket_index');
            $table->index(['destination_ip_id', 'bucket_start'], 'xbs_traffic_destination_ip_bucket_index');
            $table->index(['outbound_id', 'bucket_start'], 'xbs_traffic_outbound_bucket_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xbs_traffic_hourly');
        Schema::dropIfExists('xbs_outbounds');
        Schema::dropIfExists('xbs_ip_addresses');
        Schema::dropIfExists('xbs_domains');
        Schema::dropIfExists('xbs_report_batches');
    }
};
