<?php

$db_path = __DIR__ . '/../database/todoapp.db';

try {

    $db = new PDO("sqlite:" . $db_path);

    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // SQLite kilitlenmelerinde biraz bekle
    $db->exec("PRAGMA busy_timeout = 5000");

} catch (PDOException $e) {

    die("Veritabanı bağlantı hatası: " . $e->getMessage());

}

?>