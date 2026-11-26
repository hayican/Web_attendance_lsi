<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_shift_schedules', function (Blueprint $table): void {
            $table->string('notes', 255)->nullable();
            $table->date('replacement_for_date')->nullable();

            $table->unique(['user_id', 'replacement_for_date']);
        });
    }

    public function down(): void
    {
        Schema::table('user_shift_schedules', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'replacement_for_date']);
            $table->dropColumn(['notes', 'replacement_for_date']);
        });
    }
};
