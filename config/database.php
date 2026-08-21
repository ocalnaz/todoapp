<?php

$db_path = __DIR__ . '/../database/todoapp.db';

try {

    $db = new PDO("sqlite:" . $db_path);

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // SQLite kilitlenmelerinde biraz bekle
    $db->exec("PRAGMA busy_timeout = 5000");

    // Yabancı anahtarların çalışmasını garanti et
    $db->exec("PRAGMA foreign_keys = ON");

    // 3 başarısız deneme sonrası geçici kilit bilgisi.
    // Yeni şemada kullanıcı adı + IP birlikte benzersizdir.
    $db->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            ip_address TEXT NOT NULL DEFAULT '',
            failed_attempts INTEGER NOT NULL DEFAULT 0,
            first_failed_at TEXT NULL,
            last_failed_at TEXT NULL,
            locked_until TEXT NULL,
            lock_level INTEGER NOT NULL DEFAULT 0,
            last_lock_seconds INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(username, ip_address)
        )
    ");

    // Eski kurulumlarda username alanı tek başına UNIQUE idi.
    // SQLite mevcut UNIQUE kısıtını ALTER TABLE ile kaldıramadığı için
    // tabloyu yeni şemaya güvenli ve idempotent biçimde taşı.
    $login_attempt_columns = $db->query(
        "PRAGMA table_info(login_attempts)"
    )->fetchAll(PDO::FETCH_COLUMN, 1);

    if (!in_array("ip_address", $login_attempt_columns, true)) {
        $db->beginTransaction();

        try {
            $db->exec("
                CREATE TABLE login_attempts_v2 (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL,
                    ip_address TEXT NOT NULL DEFAULT '',
                    failed_attempts INTEGER NOT NULL DEFAULT 0,
                    first_failed_at TEXT NULL,
                    last_failed_at TEXT NULL,
                    locked_until TEXT NULL,
                    lock_level INTEGER NOT NULL DEFAULT 0,
                    last_lock_seconds INTEGER NOT NULL DEFAULT 0,
                    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE(username, ip_address)
                )
            ");

            $db->exec("
                INSERT INTO login_attempts_v2
                (
                    id,
                    username,
                    ip_address,
                    failed_attempts,
                    first_failed_at,
                    last_failed_at,
                    locked_until,
                    lock_level,
                    last_lock_seconds,
                    updated_at
                )
                SELECT
                    id,
                    username,
                    '',
                    failed_attempts,
                    first_failed_at,
                    last_failed_at,
                    locked_until,
                    0,
                    0,
                    updated_at
                FROM login_attempts
            ");

            $db->exec("DROP TABLE login_attempts");
            $db->exec(
                "ALTER TABLE login_attempts_v2 RENAME TO login_attempts"
            );
            $db->commit();
        } catch (Throwable $migration_error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $migration_error;
        }
    } else {
        if (!in_array("lock_level", $login_attempt_columns, true)) {
            $db->exec(
                "ALTER TABLE login_attempts "
                . "ADD COLUMN lock_level INTEGER NOT NULL DEFAULT 0"
            );
        }

        if (!in_array("last_lock_seconds", $login_attempt_columns, true)) {
            $db->exec(
                "ALTER TABLE login_attempts "
                . "ADD COLUMN last_lock_seconds INTEGER NOT NULL DEFAULT 0"
            );
        }
    }

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup
        ON login_attempts(username, ip_address)
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

    // Görev önceliği: mevcut SQLite kurulumlarına zarar vermeden ekle.
    $task_columns = $db->query("PRAGMA table_info(tasks)")->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array("priority", $task_columns, true)) {
        $db->exec("ALTER TABLE tasks ADD COLUMN priority TEXT NOT NULL DEFAULT 'normal'");
    }
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_tasks_priority
        ON tasks(priority)
    ");

    // Gönderim dosyası sürümleme: mevcut kayıtların sürümü 1 kabul edilir.
    $submission_columns = $db->query(
        "PRAGMA table_info(task_submissions)"
    )->fetchAll(PDO::FETCH_COLUMN, 1);

    if (
        !empty($submission_columns)
        && !in_array("version_no", $submission_columns, true)
    ) {
        $db->exec(
            "ALTER TABLE task_submissions "
            . "ADD COLUMN version_no INTEGER NOT NULL DEFAULT 1"
        );
    }

    if (!empty($submission_columns)) {
        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_task_submissions_version
            ON task_submissions(task_id, user_id, version_no)
        ");
    }

} catch (PDOException $e) {

    error_log("Todoapp database error: " . $e->getMessage());
    http_response_code(500);
    die("Veritabanı bağlantısı kurulamadı. Lütfen daha sonra tekrar deneyin.");

}

?>
