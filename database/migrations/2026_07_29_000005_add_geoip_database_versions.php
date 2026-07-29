<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('xbs_ip_addresses', function (Blueprint $table): void {
            $table->string('country_database_version', 32)->nullable();
            $table->string('asn_database_version', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('xbs_ip_addresses', function (Blueprint $table): void {
            $table->dropColumn(['country_database_version', 'asn_database_version']);
        });
    }
};
