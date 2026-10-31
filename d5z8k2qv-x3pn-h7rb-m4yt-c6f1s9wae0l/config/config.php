<?php

/**
 * Composer's autoloader.
 *
 * PhpSpreadsheet is the engine behind all three staged importers (members,
 * Staff VG schedule, facility requests). Nothing else in the app ever required
 * this file, so over HTTP every one of those uploads died inside
 * `\PhpOffice\PhpSpreadsheet\IOFactory` with "Class not found", which the
 * importers catch and report to the admin as the misleading "That file could
 * not be opened as a spreadsheet. Please save it as .xlsx and try again." —
 * advice that cannot possibly help, because the file was never the problem.
 * The CLI harnesses that tested the importers required it themselves, which is
 * why the gap only appears through the browser.
 *
 * config/config.php is the one file every entry point already loads, so this is
 * the single place that fixes all three. Guarded with file_exists so a checkout
 * without `composer install` still boots and shows its own clearer error rather
 * than a blank 500.
 */
$__vbAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($__vbAutoload)) {
    require_once $__vbAutoload;
}
unset($__vbAutoload);

/**
 * Load environment variables from .env file
 */
function loadEnv($path)
{
    // A missing .env is the usual cause of a blank 500 on a fresh deploy: the
    // file is gitignored, so a clone/pull never brings it. Name the exact path
    // and the fix, because this error is read by whoever is staring at a white
    // page with no other clue.
    if (!file_exists($path)) {
        throw new Exception(
            '.env file not found at ' . $path
            . ' — copy .env.example to .env and fill in the database credentials.'
        );
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

// A .env that exists but is missing a key fails later and far less obviously —
// as an "Undefined array key" inside getDB(). Checking here means the message
// names the missing setting instead.
$missingEnv = array_values(array_filter(
    ['DB_HOST', 'DB_USERNAME', 'DB_DATABASE'],
    fn($k) => !isset($_ENV[$k]) || $_ENV[$k] === ''
));
if ($missingEnv) {
    throw new Exception('.env is missing required setting(s): ' . implode(', ', $missingEnv));
}
// DB_PASSWORD may legitimately be empty (XAMPP default), but must be defined.
if (!isset($_ENV['DB_PASSWORD'])) {
    $_ENV['DB_PASSWORD'] = '';
}

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
