<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xbs_report_batches', function (Blueprint $table): void {
            $table->unsignedInteger('reported_pending_batches')->nullable()->after('quality');
            $table->unsignedInteger('reported_retry_attempt')->nullable()->after('reported_pending_batches');
        });
    }

    public function down(): void
    {
        Schema::table('xbs_report_batches', function (Blueprint $table): void {
            $table->dropColumn(['reported_pending_batches', 'reported_retry_attempt']);
        });
    }
};
