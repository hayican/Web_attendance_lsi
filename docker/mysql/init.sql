CREATE DATABASE IF NOT EXISTS `attendance_lsi` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `attendance_lsi_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Accounts: development credentials below; override them for any deployed environment.
CREATE USER IF NOT EXISTS 'lsi_migrator'@'%' IDENTIFIED BY 'lsi_migrator_secret';
CREATE USER IF NOT EXISTS 'lsi_user'@'%' IDENTIFIED BY 'lsi_secret';

-- Migration account: full privileges on both databases (migrations, DDL, seeders).
GRANT ALL PRIVILEGES ON `attendance_lsi`.* TO 'lsi_migrator'@'%';
GRANT ALL PRIVILEGES ON `attendance_lsi_test`.* TO 'lsi_migrator'@'%';

-- Application account: DML only, no DDL.
GRANT SELECT, INSERT, UPDATE, DELETE ON `attendance_lsi`.* TO 'lsi_user'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON `attendance_lsi_test`.* TO 'lsi_user'@'%';

-- audit_logs is append-only: the audit trail must never be rewritten, so the
-- application account gets SELECT + INSERT only there (no UPDATE/DELETE).
SET PERSIST partial_revokes = ON;
REVOKE UPDATE, DELETE ON `attendance_lsi`.`audit_logs` FROM 'lsi_user'@'%';
REVOKE UPDATE, DELETE ON `attendance_lsi_test`.`audit_logs` FROM 'lsi_user'@'%';
GRANT SELECT, INSERT ON `attendance_lsi`.`audit_logs` TO 'lsi_user'@'%';
GRANT SELECT, INSERT ON `attendance_lsi_test`.`audit_logs` TO 'lsi_user'@'%';

FLUSH PRIVILEGES;
