CREATE DATABASE IF NOT EXISTS `attendance_lsi` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `attendance_lsi_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `attendance_lsi`.* TO 'lsi_user'@'%';
GRANT ALL PRIVILEGES ON `attendance_lsi_test`.* TO 'lsi_user'@'%';
FLUSH PRIVILEGES;
