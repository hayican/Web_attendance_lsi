<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string UPDATE_TRIGGER = 'audit_logs_prevent_update';

    private const string DELETE_TRIGGER = 'audit_logs_prevent_delete';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('can_view_audit_logs')->default(false);
            $table->timestamp('deactivated_at')->nullable();
        });

        DB::table('users')
            ->where('is_active', false)
            ->whereNull('deactivated_at')
            ->update(['deactivated_at' => DB::raw('COALESCE(updated_at, created_at)')]);

        Schema::table('attendance_logs', function (Blueprint $table): void {
            $table->time('schedule_start_time')->nullable();
            $table->time('schedule_end_time')->nullable();
            $table->unsignedInteger('grace_period_minutes')->nullable();
            $table->unsignedInteger('auto_close_hours')->nullable();
            $table->decimal('office_lat', 10, 7)->nullable();
            $table->decimal('office_lng', 10, 7)->nullable();
            $table->unsignedInteger('office_radius_m')->nullable();
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('prev_hash', 64)->nullable();
            $table->string('row_hash', 64)->nullable();
        });

        // MySQL rejects trigger creation when binary logging is enabled
        // unless the user has SUPER or log_bin_trust_function_creators=1.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS `'.self::UPDATE_TRIGGER.'`');
            DB::unprepared('DROP TRIGGER IF EXISTS `'.self::DELETE_TRIGGER.'`');
            DB::unprepared(
                'CREATE TRIGGER `'.self::UPDATE_TRIGGER.'` BEFORE UPDATE ON `audit_logs` FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'audit_logs is append-only\''
            );
            DB::unprepared(
                'CREATE TRIGGER `'.self::DELETE_TRIGGER.'` BEFORE DELETE ON `audit_logs` FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'audit_logs is append-only\''
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS `'.self::UPDATE_TRIGGER.'`');
            DB::unprepared('DROP TRIGGER IF EXISTS `'.self::DELETE_TRIGGER.'`');
        }

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropColumn(['prev_hash', 'row_hash']);
        });

        Schema::table('attendance_logs', function (Blueprint $table): void {
            $table->dropColumn([
                'schedule_start_time',
                'schedule_end_time',
                'grace_period_minutes',
                'auto_close_hours',
                'office_lat',
                'office_lng',
                'office_radius_m',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['can_view_audit_logs', 'deactivated_at']);
        });
    }
};
