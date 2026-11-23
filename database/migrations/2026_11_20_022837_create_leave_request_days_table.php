<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('leave_date');
            $table->decimal('day_value', 3, 1);
            $table->string('status', 20)->default('PENDING');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['leave_request_id', 'leave_date']);
            $table->index(['user_id', 'leave_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_days');
    }
};
