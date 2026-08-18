<?php

require_once 'config/database.php';

try {

    // USERS TABLOSU
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            full_name TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // TASKS TABLOSU
    $db->exec("
        CREATE TABLE IF NOT EXISTS tasks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT,
            assigned_to INTEGER NOT NULL,
            assigned_by INTEGER NOT NULL,
            due_date DATE,
            status TEXT DEFAULT 'bekliyor',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (assigned_to) REFERENCES users(id),
            FOREIGN KEY (assigned_by) REFERENCES users(id)
        )
    ");

    // GÖREV ÇALIŞMALARI
    $db->exec("
        CREATE TABLE IF NOT EXISTS task_submissions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (task_id) REFERENCES tasks(id),
            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");

    // REVİZYONLAR
    $db->exec("
        CREATE TABLE IF NOT EXISTS revisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_id INTEGER NOT NULL,
            admin_id INTEGER NOT NULL,
            message TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (task_id) REFERENCES tasks(id),
            FOREIGN KEY (admin_id) REFERENCES users(id)
        )
    ");

    // BİLDİRİMLER
    $db->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            message TEXT NOT NULL,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

            FOREIGN KEY (user_id) REFERENCES users(id)
        )
    ");

    echo "Tüm tablolar başarıyla oluşturuldu!";

} catch (PDOException $e) {

    echo "Hata: " . $e->getMessage();

}

?>