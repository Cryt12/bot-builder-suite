<?php

declare(strict_types=1);

/**
 * Bootstrap the configured database before running Laravel migrations.
 * Supports pgsql, mysql, and sqlite.
 */

$root = dirname(__DIR__);

loadEnvFile($root . '/.env');
loadEnvFile($root . '/.env.example', false);

$connection = envValue('DB_CONNECTION', 'pgsql');

try {
    match ($connection) {
        'pgsql' => ensurePostgresDatabase(),
        'mysql' => ensureMySqlDatabase(),
        'sqlite' => ensureSqliteDatabase(),
        default => info("Skipping database bootstrap for unsupported driver [{$connection}]."),
    };
} catch (Throwable $e) {
    fwrite(STDERR, "[db:ensure] {$e->getMessage()}\n");
    exit(1);
}

function ensurePostgresDatabase(): void
{
    $host = envValue('DB_HOST', '127.0.0.1');
    $port = envValue('DB_PORT', '5432');
    $database = envValue('DB_DATABASE', 'helix');
    $username = envValue('DB_USERNAME', 'postgres');
    $password = envValue('DB_PASSWORD', '');
    $maintenanceDb = envValue('DB_MAINTENANCE_DATABASE', 'postgres');

    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$maintenanceDb}",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $statement = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :database');
    $statement->execute(['database' => $database]);

    if ($statement->fetchColumn()) {
        info("PostgreSQL database [{$database}] already exists.");
        return;
    }

    $pdo->exec('CREATE DATABASE "' . str_replace('"', '""', $database) . '"');
    info("Created PostgreSQL database [{$database}].");
}

function ensureMySqlDatabase(): void
{
    $host = envValue('DB_HOST', '127.0.0.1');
    $port = envValue('DB_PORT', '3306');
    $database = envValue('DB_DATABASE', 'helix');
    $username = envValue('DB_USERNAME', 'root');
    $password = envValue('DB_PASSWORD', '');

    $pdo = new PDO(
        "mysql:host={$host};port={$port}",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $database) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    info("Ensured MySQL database [{$database}] exists.");
}

function ensureSqliteDatabase(): void
{
    $database = envValue('DB_DATABASE', databasePath('database.sqlite'));

    if ($database === ':memory:') {
        info('Using in-memory SQLite database.');
        return;
    }

    $path = normalizePath($database);
    $directory = dirname($path);

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    if (! file_exists($path)) {
        touch($path);
        info("Created SQLite database file [{$path}].");
        return;
    }

    info("SQLite database file [{$path}] already exists.");
}

function loadEnvFile(string $path, bool $overwrite = true): void
{
    if (! file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if ($value !== '' && (($value[0] ?? '') === '"' || ($value[0] ?? '') === "'")) {
            $value = trim($value, "\"'");
        }

        if (! $overwrite && getenv($name) !== false) {
            continue;
        }

        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

function envValue(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return (string) $value;
}

function databasePath(string $path = ''): string
{
    return dirname(__DIR__) . '/database/' . ltrim($path, '/');
}

function normalizePath(string $path): string
{
    if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1) {
        return $path;
    }

    return dirname(__DIR__) . '/' . ltrim($path, '/');
}

function info(string $message): void
{
    fwrite(STDOUT, "[db:ensure] {$message}\n");
}
