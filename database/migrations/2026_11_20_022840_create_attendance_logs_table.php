<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->foreignId('leave_request_id')->nullable()->constrained('leave_requests')->restrictOnDelete();
            $table->string('source', 20)->default('APP');
            $table->date('work_date');
            $table->unsignedInteger('attempt_no')->default(1);
            $table->tinyInteger('is_active_attempt')->nullable()->default(1);

            $table->timestamp('clock_in_at')->nullable();
            $table->string('clock_in_photo_url')->nullable();
            $table->decimal('clock_in_lat', 10, 7)->nullable();
            $table->decimal('clock_in_lng', 10, 7)->nullable();
            $table->decimal('clock_in_accuracy_m', 8, 2)->nullable();
            $table->decimal('clock_in_distance_m', 10, 2)->nullable();
            $table->string('clock_in_device_id', 64)->nullable();
            $table->string('clock_in_ip', 45)->nullable();
            $table->text('clock_in_user_agent')->nullable();
            $table->text('clock_in_notes')->nullable();
            $table->string('clock_in_idempotency_key', 36)->nullable()->unique();
            $table->unsignedInteger('late_minutes')->default(0);

            $table->timestamp('clock_out_at')->nullable();
            $table->string('clock_out_photo_url')->nullable();
            $table->decimal('clock_out_lat', 10, 7)->nullable();
            $table->decimal('clock_out_lng', 10, 7)->nullable();
            $table->decimal('clock_out_accuracy_m', 8, 2)->nullable();
            $table->decimal('clock_out_distance_m', 10, 2)->nullable();
            $table->string('clock_out_device_id', 64)->nullable();
            $table->string('clock_out_ip', 45)->nullable();
            $table->text('clock_out_user_agent')->nullable();
            $table->text('clock_out_notes')->nullable();
            $table->string('clock_out_idempotency_key', 36)->nullable()->unique();
            $table->unsignedInteger('early_leave_minutes')->default(0);

            $table->string('clock_in_status', 30);
            $table->json('clock_in_anomaly_flags')->nullable();
            $table->foreignId('clock_in_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('clock_in_reviewed_at')->nullable();
            $table->text('clock_in_reject_reason')->nullable();

            $table->string('clock_out_status', 30)->nullable();
            $table->json('clock_out_anomaly_flags')->nullable();
            $table->foreignId('clock_out_reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('clock_out_reviewed_at')->nullable();
            $table->text('clock_out_reject_reason')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'work_date', 'is_active_attempt'], 'attendance_active_attempt_unique');
            $table->index(['user_id', 'work_date']);
            $table->index(['clock_in_device_id', 'work_date']);
            $table->index(['clock_out_device_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
