<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->json('face_signature')->nullable()->after('is_active');
            $table->timestamp('face_registered_at')->nullable()->after('face_signature');
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->boolean('face_verified')->default(false)->after('is_flagged');
            $table->decimal('liveness_score', 5, 2)->nullable()->after('face_verified');
            $table->json('verification_meta')->nullable()->after('liveness_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            $table->dropColumn(['face_verified', 'liveness_score', 'verification_meta']);
        });

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['face_signature', 'face_registered_at']);
        });
    }
};
