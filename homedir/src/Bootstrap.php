<?php
declare(strict_types=1);

/**
 * Fælles start for web, cron og tests: hjemmemappen i BASE_DIR og autoload af Whisper\ fra src/.
 * Kalderen må sætte BASE_DIR før (migrate.php kører den nye commits kode mod hjemmemappens config).
 */

if (!defined('BASE_DIR')) {
    define('BASE_DIR', dirname(__DIR__));
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Whisper\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// constant() i stedet for BASE_DIR: PHPStan kender ikke konstanter, der defineres betinget
\Whisper\Config::setBaseDir((string) constant('BASE_DIR'));
