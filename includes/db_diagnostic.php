<?php
require_once dirname(__DIR__) . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

function diagnosticValue(?string $value): string
{
    return ($value === null || $value === '') ? '(empty)' : $value;
}

function diagnosticPasswordStatus(?string $value): string
{
    if ($value === null || $value === '') {
        return 'missing';
    }
    return 'set, length ' . strlen($value);
}

echo "RHU database diagnostic\n";
echo "=======================\n";
echo "Host: " . diagnosticValue($DB_HOST ?? null) . "\n";
echo "Port: " . diagnosticValue($DB_PORT ?? null) . "\n";
echo "Database: " . diagnosticValue($DB_NAME ?? null) . "\n";
echo "Username: " . diagnosticValue($DB_USER ?? null) . "\n";
echo "Password: " . diagnosticPasswordStatus($DB_PASS ?? null) . "\n";
echo "PDO MySQL extension: " . (extension_loaded('pdo_mysql') ? 'loaded' : 'missing') . "\n";
echo "Connection: " . (($pdo ?? null) instanceof PDO ? 'connected' : 'failed') . "\n";

if (!empty($lastDbError)) {
    echo "Last error: " . $lastDbError . "\n";
}

if (($pdo ?? null) instanceof PDO) {
    try {
        echo "Server version: " . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) . "\n";
        echo "Current database: " . ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '(none)') . "\n";
    } catch (Throwable $exception) {
        echo "Connected, but diagnostic query failed: " . $exception->getMessage() . "\n";
    }
}

