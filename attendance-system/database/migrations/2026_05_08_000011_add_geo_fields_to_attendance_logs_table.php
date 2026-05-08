<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->text('geo_address')->nullable()->after('ip_address');
            $table->decimal('geo_latitude', 10, 7)->nullable()->after('geo_address');
            $table->decimal('geo_longitude', 10, 7)->nullable()->after('geo_latitude');
            $table->decimal('geo_accuracy', 8, 2)->nullable()->after('geo_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn(['geo_address', 'geo_latitude', 'geo_longitude', 'geo_accuracy']);
        });
    }
};
