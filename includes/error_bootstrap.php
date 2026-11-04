<?php
ini_set('log_errors', '1');
ini_set('error_log', dirname(__DIR__) . '/logs/rhu_error.log');

if (rhuDebugEnabled()) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

set_exception_handler(function ($exception) {
    error_log('Uncaught ' . get_class($exception) . ': ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Application error. Check rhu_error.log in your hosting file manager.";
    if (rhuDebugEnabled()) {
        echo "\n\n" . $exception;
    }
    exit;
});

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    error_log('Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Application fatal error. Check rhu_error.log in your hosting file manager.";
        if (rhuDebugEnabled()) {
            echo "\n\n" . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'];
        }
    }
});

function rhuDebugEnabled()
{
    if (isset($_GET['debug']) && (string)$_GET['debug'] === '1') {
        return true;
    }

    $value = getenv('RHU_DEBUG');
    if ($value === false || $value === '') {
        $envPath = dirname(__DIR__) . '/.env';
        if (is_readable($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
                $parts = explode('=', $line, 2);
                $key = $parts[0];
                $envValue = $parts[1];
                if (trim($key) === 'RHU_DEBUG') {
                    $value = trim(trim($envValue), "\"'");
                    break;
                }
            }
        }
    }
    return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
}
