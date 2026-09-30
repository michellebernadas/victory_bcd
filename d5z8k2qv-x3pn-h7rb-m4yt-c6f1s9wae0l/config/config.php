<?php

/**
 * Load environment variables from .env file
 */
function loadEnv($path)
{
    if (!file_exists($path)) {
        throw new Exception('.env file not found');
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        if (preg_match('/^(["\'])(.*)\\1$/', $value, $matches)) {
            $value = $matches[2];
        }

        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
        }
    }
}

loadEnv(__DIR__ . '/../.env');

/**
 * Cache-busted URL for a local asset.
 *
 * Browsers cache js/ and css/ aggressively, and because the filename never
 * changes a deploy can leave a stale script in place — which shows up as
 * "function is not defined" or a feature that simply does nothing. Appending
 * the file's mtime changes the URL whenever the file changes, so the browser
 * refetches exactly when it should and keeps caching the rest of the time.
 *
 * Falls back to the plain path if the file can't be stat'd.
 */
function asset(string $path): string
{
    $full = __DIR__ . '/../' . ltrim($path, '/');
    $mt   = @filemtime($full);
    return $mt ? ($path . '?v=' . $mt) : $path;
}

function getDB()
{
    try {
        $dsn = "mysql:host=" . $_ENV['DB_HOST'] . ";port=3306;dbname=" . $_ENV['DB_DATABASE'] . ";charset=utf8mb4";
        $pdo = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        die("Database connection failed. Please check your configuration.");
    }
}
