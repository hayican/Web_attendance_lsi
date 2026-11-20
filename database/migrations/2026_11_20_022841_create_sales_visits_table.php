<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('outlet_location_id')->constrained('locations')->restrictOnDelete();
            $table->string('source', 20)->default('APP');
            $table->date('visit_date');

            $table->timestamp('visit_in_at')->nullable();
            $table->string('visit_in_photo_url')->nullable();
            $table->decimal('visit_in_lat', 10, 7)->nullable();
            $table->decimal('visit_in_lng', 10, 7)->nullable();
            $table->decimal('visit_in_accuracy_m', 8, 2)->nullable();
            $table->decimal('visit_in_distance_m', 10, 2)->nullable();
            $table->string('visit_in_device_id', 64)->nullable();
            $table->string('visit_in_ip', 45)->nullable();
            $table->text('visit_in_user_agent')->nullable();
            $table->text('visit_in_notes')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->string('visit_in_idempotency_key', 36)->nullable()->unique();

            $table->timestamp('visit_out_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->foreignId('activity_category_id')->nullable()->constrained('activity_categories')->restrictOnDelete();
            $table->text('visit_notes')->nullable();
            $table->decimal('visit_out_lat', 10, 7)->nullable();
            $table->decimal('visit_out_lng', 10, 7)->nullable();
            $table->decimal('visit_out_accuracy_m', 8, 2)->nullable();
            $table->decimal('visit_out_distance_m', 10, 2)->nullable();
            $table->string('visit_out_device_id', 64)->nullable();
            $table->string('visit_out_ip', 45)->nullable();
            $table->text('visit_out_user_agent')->nullable();
            $table->string('visit_out_idempotency_key', 36)->nullable()->unique();

            $table->string('visit_status', 30)->default('IN_PROGRESS');
            $table->string('verification_status', 30)->default('AUTO_APPROVED');
            $table->json('anomaly_flags')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reject_reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'visit_date']);
            $table->index(['visit_in_device_id', 'visit_date']);
            $table->index(['visit_out_device_id', 'visit_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_visits');
    }
};
