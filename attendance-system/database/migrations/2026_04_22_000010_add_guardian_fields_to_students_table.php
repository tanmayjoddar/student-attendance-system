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
            $table->string('parent_name')->nullable()->after('last_name');
            $table->string('father_name')->nullable()->after('parent_name');
            $table->string('mother_name')->nullable()->after('father_name');
            $table->text('address')->nullable()->after('mother_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['parent_name', 'father_name', 'mother_name', 'address']);
        });
    }
};
