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
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->date('date');
            $table->enum('type', ['in', 'out']);
            $table->timestamp('recorded_time');
            $table->timestamp('stated_time')->nullable();
            $table->string('ip_address');
            $table->boolean('is_flagged')->default(false);
            $table->foreignId('submitted_by')->constrained('users');
            $table->timestamps();

            // Prevent duplicate check-in/out for same student on same date
            $table->unique(['student_id', 'date', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
