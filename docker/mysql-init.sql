CREATE DATABASE IF NOT EXISTS vending_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON vending_test.* TO 'vending'@'%';
FLUSH PRIVILEGES;
