<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_prevent_delete');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_prevent_update
            BEFORE UPDATE ON audit_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_logs_prevent_delete
            BEFORE DELETE ON audit_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
            END
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_prevent_update');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_prevent_delete');
    }
};
