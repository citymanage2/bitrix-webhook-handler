<?php
// Включаем показ всех ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== BITRIX24 WEBHOOK HANDLER - TEST MODE ===\n";
echo "Time: " . date('Y-m-d H:i:s') . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Script: " . __FILE__ . "\n";
echo "Working directory: " . getcwd() . "\n\n";

// Проверка GET параметра для логов
if (isset($_GET['show_logs'])) {
    echo "=== LOG VIEWER ACTIVATED ===\n\n";
    
    $logFiles = [
        '/tmp/render-b24.log',
        __DIR__ . '/bitrix-webhook.log',
        '/tmp/test.log'
    ];
    
    foreach ($logFiles as $file) {
        echo "Checking: $file ... ";
        if (file_exists($file)) {
            echo "EXISTS\n";
            echo "Content:\n";
            echo "----------------------------------------\n";
            echo file_get_contents($file);
            echo "----------------------------------------\n\n";
        } else {
            echo "NOT FOUND\n";
        }
    }
    exit;
}

// Тест записи в лог
$logFile = '/tmp/render-b24.log';
echo "Testing log write to: $logFile\n";
$result = @file_put_contents($logFile, '['.date('Y-m-d H:i:s').'] Test entry'.PHP_EOL, FILE_APPEND);
echo "Result: " . ($result !== false ? "SUCCESS ($result bytes)" : "FAILED") . "\n";
echo "Log file exists: " . (file_exists($logFile) ? "YES" : "NO") . "\n";
echo "Log file readable: " . (is_readable($logFile) ? "YES" : "NO") . "\n\n";

// Читаем webhook payload
echo "=== WEBHOOK PAYLOAD ===\n";
$raw = file_get_contents('php://input');
echo "Payload length: " . strlen($raw) . "\n";
echo "Content:\n";
var_dump($raw);
echo "\n";

echo "=== REQUEST INFO ===\n";
echo "Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'unknown') . "\n";
echo "URI: " . ($_SERVER['REQUEST_URI'] ?? 'unknown') . "\n";
echo "Query string: " . ($_SERVER['QUERY_STRING'] ?? 'none') . "\n";
echo "\n";

echo "=== END TEST ===\n";
