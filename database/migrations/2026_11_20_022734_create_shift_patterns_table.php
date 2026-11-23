<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->foreignId('mon_shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->foreignId('tue_shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->foreignId('wed_shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->foreignId('thu_shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->foreignId('fri_shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->foreignId('sat_shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->foreignId('sun_shift_id')->nullable()->constrained('shifts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_patterns');
    }
};
