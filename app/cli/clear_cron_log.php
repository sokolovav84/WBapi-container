<?php
// Путь к лог-файлу
$logFile = '/var/log/cron.log';

// Максимальный размер (25 Мб)
$maxSize = 25 * 1024 * 1024; // в байтах

if (file_exists($logFile)) {
    $size = filesize($logFile);

    if ($size === false) {
        error_log("Не удалось получить размер файла {$logFile}");
        exit(1);
    }

    if ($size > $maxSize) {
        // Очистим файл (сохраним пустым)
        $fp = fopen($logFile, 'w');
        if ($fp) {
            fclose($fp);
            echo "Файл {$logFile} очищен (размер был " . round($size/1024/1024, 2) . " Мб)\n";
        } else {
            error_log("Не удалось очистить файл {$logFile}");
            exit(1);
        }
    }
}
