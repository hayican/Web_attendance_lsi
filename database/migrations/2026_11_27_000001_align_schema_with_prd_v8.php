<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_shift_schedules', function (Blueprint $table): void {
            $table->string('compensation_source_type', 30)->nullable();
            $table->unsignedBigInteger('compensation_source_id')->nullable();
            $table->date('compensation_source_date')->nullable();

            $table->index(['compensation_source_type', 'compensation_source_id'], 'ushift_compensation_source_index');
            $table->unique(['user_id', 'compensation_source_date'], 'ushift_user_compensation_date_unique');
        });

        // Legacy ganti-hari column becomes the PRD v8 polymorphic source; date moves to compensation_source_date.
        DB::table('user_shift_schedules')
            ->whereNotNull('replacement_for_date')
            ->update(['compensation_source_date' => DB::raw('replacement_for_date')]);

        Schema::table('user_shift_schedules', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->change();
        });

        Schema::table('user_shift_schedules', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'replacement_for_date']);
            $table->dropColumn('replacement_for_date');
        });

        Schema::table('sales_visits', function (Blueprint $table): void {
            $table->decimal('outlet_lat', 10, 7)->nullable()->after('outlet_location_id');
            $table->decimal('outlet_lng', 10, 7)->nullable()->after('outlet_lat');
            $table->unsignedInteger('outlet_radius_m')->nullable()->after('outlet_lng');
        });

        Schema::table('holidays', function (Blueprint $table): void {
            $table->unsignedBigInteger('location_scope_id')->default(0)->after('location_id');
        });

        DB::table('holidays')->update(['location_scope_id' => DB::raw('COALESCE(location_id, 0)')]);

        Schema::table('holidays', function (Blueprint $table): void {
            $table->unique(['holiday_date', 'location_scope_id'], 'holidays_date_scope_unique');
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('actor_id')->nullable()->change();
        });

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('actor_id')->nullable(false)->change();
        });

        Schema::table('holidays', function (Blueprint $table): void {
            $table->dropUnique('holidays_date_scope_unique');
            $table->dropColumn('location_scope_id');
        });

        Schema::table('sales_visits', function (Blueprint $table): void {
            $table->dropColumn(['outlet_lat', 'outlet_lng', 'outlet_radius_m']);
        });

        Schema::table('user_shift_schedules', function (Blueprint $table): void {
            $table->date('replacement_for_date')->nullable();
        });

        DB::table('user_shift_schedules')
            ->whereNotNull('compensation_source_date')
            ->update(['replacement_for_date' => DB::raw('compensation_source_date')]);

        Schema::table('user_shift_schedules', function (Blueprint $table): void {
            $table->unique(['user_id', 'replacement_for_date']);
            $table->foreignId('created_by')->nullable(false)->change();
            $table->dropUnique('ushift_user_compensation_date_unique');
            $table->dropIndex('ushift_compensation_source_index');
            $table->dropColumn(['compensation_source_type', 'compensation_source_id', 'compensation_source_date']);
        });
    }
};
