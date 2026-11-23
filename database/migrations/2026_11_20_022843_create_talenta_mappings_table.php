<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talenta_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('mapping_key', 100)->unique();
            $table->string('mapping_value', 100);
            $table->string('description', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talenta_mappings');
    }
};
