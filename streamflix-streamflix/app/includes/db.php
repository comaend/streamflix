<?php
// StreamFlix — Datenbankverbindung (SQLite, PDO)
declare(strict_types=1);

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $pdo = null;

    public static function get(): PDO {
        if (self::$pdo === null) {
            @mkdir(dirname(DB_PATH), 0777, true);
            $dsn = 'sqlite:' . DB_PATH;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            self::$pdo = new PDO($dsn, '', '', $options);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA synchronous = NORMAL');
        }
        return self::$pdo;
    }

    public static function initSchema(string $schemaFile): void {
        $db = self::get();
        $sql = file_get_contents($schemaFile);
        if ($sql === false) {
            throw new RuntimeException("Schema-Datei nicht gefunden: $schemaFile");
        }
        $db->exec($sql);
    }
}
