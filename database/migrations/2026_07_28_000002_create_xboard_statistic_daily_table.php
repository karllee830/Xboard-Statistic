<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xbs_traffic_daily', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->date('day');
            $table->string('summary_type', 32);
            $table->unsignedBigInteger('left_id');
            $table->unsignedBigInteger('right_id');
            $table->unsignedBigInteger('upload_bytes')->default(0);
            $table->unsignedBigInteger('download_bytes')->default(0);
            $table->unsignedBigInteger('connection_count')->default(0);
            $table->unsignedBigInteger('active_seconds')->default(0);
            $table->timestamps();

            $table->unique(
                ['day', 'summary_type', 'left_id', 'right_id'],
                'xbs_daily_dimension_unique'
            );
            $table->index(['summary_type', 'day'], 'xbs_daily_type_day_index');
            $table->index(['summary_type', 'left_id', 'day'], 'xbs_daily_left_day_index');
            $table->index(['summary_type', 'right_id', 'day'], 'xbs_daily_right_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xbs_traffic_daily');
    }
};
