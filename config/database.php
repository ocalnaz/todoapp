<?php

$db_path = __DIR__ . '/../database/todoapp.db';

try {

    $db = new PDO("sqlite:" . $db_path);

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // SQLite kilitlenmelerinde biraz bekle
    $db->exec("PRAGMA busy_timeout = 5000");

    // Yabancı anahtarların çalışmasını garanti et
    $db->exec("PRAGMA foreign_keys = ON");

    // 3 başarısız deneme sonrası geçici kilit bilgisi
    $db->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            failed_attempts INTEGER NOT NULL DEFAULT 0,
            first_failed_at TEXT NULL,
            last_failed_at TEXT NULL,
            locked_until TEXT NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Başarılı, başarısız, kilitli giriş ve çıkış kayıtları
    $db->exec("
        CREATE TABLE IF NOT EXISTS login_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL,
            username TEXT NOT NULL,
            event_type TEXT NOT NULL,
            ip_address TEXT NULL,
            user_agent TEXT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON DELETE SET NULL
        )
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_login_logs_created_at
        ON login_logs(created_at)
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_login_logs_username
        ON login_logs(username)
    ");

} catch (PDOException $e) {

    error_log("Todoapp database error: " . $e->getMessage());
    http_response_code(500);
    die("Veritabanı bağlantısı kurulamadı. Lütfen daha sonra tekrar deneyin.");

}

?>
