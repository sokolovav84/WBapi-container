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


require_once "../vendor/autoload.php";

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();



// Database configuration from environment variables
$host = 'mysql';
$dbname = $_ENV['MYSQL_DATABASE'];
$username = 'root';//$_ENV['MYSQL_USER'];
$password = $_ENV['MYSQL_ROOT_PASSWORD'];
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


function columnExists($pdo, $tableName, $columnName) {
    $stmt = $pdo->prepare("SELECT COUNT(*) 
        FROM information_schema.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = :table 
            AND COLUMN_NAME = :column");

    $stmt->execute(['table' => $tableName, 'column' => $columnName]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Добавляет столбец если он не существует
 */
function addColumnIfNotExists($pdo, $tableName, $columnName, $columnDefinition) {
    if (!columnExists($pdo, $tableName, $columnName)) {
        $pdo->exec("ALTER TABLE `$tableName` ADD COLUMN $columnDefinition");
        echo "✅ Added column '$columnName' to table '$tableName'\n";
        return true;
    }
    echo "ℹ️ Column '$columnName' already exists in '$tableName'\n";
    return false;
}

function indexExists($pdo, $tableName, $indexName) {
    $stmt = $pdo->prepare("SELECT COUNT(*) 
        FROM information_schema.STATISTICS 
        WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = :table 
            AND INDEX_NAME = :index");

    $stmt->execute(['table' => $tableName, 'index' => $indexName]);
    return $stmt->fetchColumn() > 0;
}
function createTableIfNotExists($pdo, $tableName) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `{$tableName}` (
        `Id` INT AUTO_INCREMENT PRIMARY KEY
    ) ENGINE=InnoDB");
}


function addIndexIfNotExists($pdo, $tableName, $indexName, $columns, $indexType = 'INDEX') {
    if (!indexExists($pdo, $tableName, $indexName)) {
        $columnsList = is_array($columns) ? implode('`, `', $columns) : $columns;
        //echo "ALTER TABLE `$tableName` ADD $indexType `$indexName` ($columnsList)".PHP_EOL;

        $pdo->exec("ALTER TABLE `$tableName` ADD $indexType `$indexName` ($columnsList)");
        echo "✅ Added $indexType '$indexName' on '$columnsList' in table '$tableName'\n";
        return true;
    }
    echo "ℹ️ Index '$indexName' already exists in table '$tableName'\n";
    return false;
}


try {
    // Reconnect to specific database
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    echo "📦 Creating tables...\n";



  $tabels = [
      "wbSettings"  => [
          "columns" => [
              "Id" => "INT AUTO_INCREMENT PRIMARY KEY",
              "name" => "`name` VARCHAR(15) NULL ",
              "accessToken" => "`accessToken` TEXT NULL ",
              "proxy" => "`proxy` VARCHAR(255) NULL ",
              "warehouse_id" => "`warehouse_id` VARCHAR(15) NULL ",
              "siteUrl" => "`siteUrl` VARCHAR(255) NULL ",
              "siteToken" => "`siteToken` VARCHAR(255) NULL ",
              "active" => "`active` TINYINT(1) NULL ",
          ],
          "indexes" => [
              "all" => " `Id`,`name`,`proxy`,`warehouse_id` ",
          ]
      ],


    "users"  => [
        "columns" => [
            "Id" => "INT AUTO_INCREMENT PRIMARY KEY",
            "username" => "`username` VARCHAR(100) NULL AFTER `Id`",
            "password" => "`password` VARCHAR(255) NULL ",
            "role" => "`role` VARCHAR(15) DEFAULT 'user'",
            "created_at" => " created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
            "updated_at" => " `updated_at` DATETIME NULL ",
        ],
        "indexes" => [
            "username" => " `username`,`role` ",
            "original"=>"  `username`"
        ]
    ],

/*
 * -- Пример пользователя (пароль: admin123)
INSERT INTO users (username, password, role) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('manager', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager'),
('user', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user');
 * */


      "products"  => [
          "columns" => [
              "Id" => "INT AUTO_INCREMENT PRIMARY KEY",
              "mainId" => "`mainId` VARCHAR(100) NULL ",
              "vendorCode" => "`vendorCode` VARCHAR(100) NULL ",
              "nomType" => "`nomType` VARCHAR(20) NULL ",
              "url" => "`url` VARCHAR(255) NULL ",
              "specifications" => "`specifications` JSON NULL ",
              "specificationsWB" => "`specificationsWB` JSON NULL ",
              "nm_id" => "`nm_id` INT NULL ",
              "imt_id" => "`imt_id` INT NULL ",
              "chrt_id" => "`chrt_id` INT NULL ",
              "barcode" => "`barcode` VARCHAR(255) NULL ",
              "stocks" => "`stocks` INT NULL ",
              "lastUpdateStocks" => "`lastUpdateStocks` DATETIME NULL ",
              "price" => "`price` DECIMAL(12,2) NULL ",
              "discount" => "`discount` INT NULL ",
              "createdAt" => "`createdAt` DATETIME NULL ",
              "updatedAt" => "`updatedAt` DATETIME NULL ",
          ],
          "indexes" => [
              "mainId" => " `mainId` ",
              "specifications" => " `vendorCode`, `mainId`, `nomTtype` ",
              "original"=>"  `nm_id`",
              "originalVC"=>"  `vendorCode`"
              //"all" => " `all` ",
          ]
      ],



     // ""  => [],
     // ""  => [],
  ];


    foreach ($tabels as $table => $props) {

        echo "====================={$table}=============================".PHP_EOL;
        createTableIfNotExists($pdo, $table);

        foreach($props['columns'] as $column => $columnDefinition) {

            addColumnIfNotExists($pdo, $table, $column, $columnDefinition);
        }

        foreach($props['indexes'] as $indexName => $indexDefinition) {
            $idexType = "INDEX";
            echo $table." - ".$indexName.PHP_EOL;
            if(strpos($indexName,"original")>-1){$idexType = "UNIQUE INDEX";}
            addIndexIfNotExists($pdo, $table, $indexName, $indexDefinition,$idexType);
        }

    }
    echo "✅ Migration successfully\n";


    $username = 'admin';
    $password = password_hash('admin123', PASSWORD_DEFAULT);
    $role = 'admin';

    $stmt = $pdo->prepare("
        INSERT INTO users (username, password, role) 
        VALUES (:username, :password, :role)
        ON DUPLICATE KEY UPDATE 
            password = VALUES(password),
            role = VALUES(role),
            updated_at = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        ':username' => $username,
        ':password' => $password,
        ':role' => $role
    ]);

    echo "User {$username} created or updated successfully".PHP_EOL;

    $username = 'apiUser';
    $password = password_hash('ParalaXXX', PASSWORD_DEFAULT);
    $role = 'admin';

    $stmt->execute([
        ':username' => $username,
        ':password' => $password,
        ':role' => $role
    ]);


    echo "User {$username} created or updated successfully".PHP_EOL;





} catch (PDOException $e) {
    //print_r($e);
    echo "❌ Migration error: " . $e->getMessage() . "\n";
    exit(1);
}