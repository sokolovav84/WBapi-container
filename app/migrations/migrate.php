<?php

/**
 * Database Migrations Script
  *
 * # Выполните миграции из PHP контейнера
 * docker-compose exec php-fpm php migrations/migrate.php
 *
 * # Или если файл в другой директории
 * docker-compose exec php-fpm php /var/www/html/migrations/migrate.php
 *
 *
 */

// Display errors for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "🚀 Starting database migrations...\n";

echo '|||';
require_once "../vendor/autoload.php";

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();



// Database configuration from environment variables
$host = 'mysql';
$dbname = $_ENV['MYSQL_DATABASE'];
$username = $_ENV['MYSQL_USER'];
$password = $_ENV['MYSQL_PASSWORD'];
$port = getenv('DB_PORT') ?: '3306';

echo "📊 Database: $dbname@$host:$port\n";

// Wait for MySQL to be ready (max 30 seconds)
$maxAttempts = 30;
$attempt = 0;

while ($attempt < $maxAttempts) {
    try {
        $pdo = new PDO("mysql:host=$host;port=$port", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if database exists, create if not
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$dbname`");

        echo "✅ Connected to MySQL successfully\n";
        break;

    } catch (PDOException $e) {
        $attempt++;
        echo "⏳ Waiting for MySQL... (attempt $attempt/$maxAttempts)\n";
        sleep(2);

        if ($attempt >= $maxAttempts) {
            echo "❌ MySQL connection failed: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

try {
    // Reconnect to specific database
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    echo "📦 Creating tables...\n";

    // 1. Table: users
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `email` VARCHAR(100) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `role` ENUM('user', 'admin', 'moderator') DEFAULT 'user',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_email` (`email`),
        INDEX `idx_username` (`username`)
    ) ENGINE=InnoDB");

    echo "✅ Table 'users' ready\n";

    // 2. Table: products
    $pdo->exec("CREATE TABLE IF NOT EXISTS `products` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `title` VARCHAR(255) NOT NULL,
        `slug` VARCHAR(255) NOT NULL UNIQUE,
        `price` DECIMAL(10,2) NOT NULL,
        `stock_quantity` INT DEFAULT 0,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_slug` (`slug`)
    ) ENGINE=InnoDB");

    echo "✅ Table 'products' ready\n";

    // 3. Table: categories
    $pdo->exec("CREATE TABLE IF NOT EXISTS `categories` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `slug` VARCHAR(100) NOT NULL UNIQUE,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_slug` (`slug`)
    ) ENGINE=InnoDB");

    echo "✅ Table 'categories' ready\n";

    // 4. Add category_id to products if not exists
    $columnExists = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = '$dbname' 
        AND TABLE_NAME = 'products' 
        AND COLUMN_NAME = 'category_id'")->fetchColumn();

    if ($columnExists == 0) {
        $pdo->exec("ALTER TABLE `products` ADD COLUMN `category_id` INT NULL AFTER `slug`");
        $pdo->exec("ALTER TABLE `products` ADD CONSTRAINT `fk_products_category` 
            FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL");
        echo "✅ Added 'category_id' to products\n";
    }

    // 5. Insert sample data if tables are empty
    $usersCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($usersCount == 0) {
        $pdo->exec("INSERT INTO `users` (username, email, password, role) VALUES
            ('admin', 'admin@example.com', '" . password_hash('admin123', PASSWORD_DEFAULT) . "', 'admin'),
            ('user1', 'user1@example.com', '" . password_hash('user123', PASSWORD_DEFAULT) . "', 'user')");
        echo "✅ Added sample users\n";
    }

    $categoriesCount = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($categoriesCount == 0) {
        $pdo->exec("INSERT INTO `categories` (name, slug) VALUES
            ('Electronics', 'electronics'),
            ('Clothing', 'clothing'),
            ('Books', 'books')");
        echo "✅ Added sample categories\n";
    }

    $productsCount = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    if ($productsCount == 0) {
        $pdo->exec("INSERT INTO `products` (title, slug, price, category_id, stock_quantity) VALUES
            ('Laptop', 'laptop', 999.99, 1, 10),
            ('T-Shirt', 't-shirt', 19.99, 2, 50),
            ('Programming Book', 'programming-book', 39.99, 3, 25)");
        echo "✅ Added sample products\n";
    }

    echo "🎉 Database migrations completed successfully!\n";
    echo "📊 Tables created: users, products, categories\n";
    echo "📊 Sample data inserted\n";

} catch (PDOException $e) {
    echo "❌ Migration error: " . $e->getMessage() . "\n";
    exit(1);
}