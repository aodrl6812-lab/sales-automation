<?php

function envv(string $key, string $default = ''): string {
    static $env = null;
    if ($env === null) {
        $env = [];
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
                $env[trim($k)] = trim($v);
            }
        }
    }
    return $env[$key] ?? $default;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $host = envv('DB_HOST', '127.0.0.1');
    $name = envv('DB_NAME', 'ship_new');
    $user = envv('DB_USER', 'root');
    $pass = envv('DB_PASS', '');

    $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}
