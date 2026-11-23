<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('base_quota', 4, 1)->default(12.0);
            $table->decimal('prorated_deduction', 4, 1)->default(0.0);
            $table->decimal('collective_leave_deduction', 4, 1)->default(0.0);
            $table->decimal('used_days', 4, 1)->default(0.0);
            $table->decimal('pending_days', 4, 1)->default(0.0);
            $table->decimal('adjustment_days', 4, 1)->default(0.0);
            $table->timestamps();

            $table->unique(['user_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
    }
};
