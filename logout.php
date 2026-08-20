<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . "/config/database.php";

$user_id = isset($_SESSION["user_id"])
    ? (int) $_SESSION["user_id"]
    : null;

$username = trim((string) ($_SESSION["username"] ?? ""));

if ($username === "") {
    $username = "[oturum yok]";
}

try {

    $stmt = $db->prepare("
        INSERT INTO login_logs
        (
            user_id,
            username,
            event_type,
            ip_address,
            user_agent,
            created_at
        )
        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");

    $stmt->execute([
        $user_id,
        $username,
        "logout",
        $_SERVER["REMOTE_ADDR"] ?? null,
        $_SERVER["HTTP_USER_AGENT"] ?? null
    ]);

} catch (PDOException $e) {

    // Log yazılamasa bile kullanıcı güvenli biçimde çıkış yapabilsin.

}

session_unset();
session_destroy();

header("Location: login.php");
exit;

?>
