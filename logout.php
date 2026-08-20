<?php

require_once __DIR__ . "/config/session.php";

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

if (ini_get("session.use_cookies")) {
    $cookie_params = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        [
            "expires" => time() - 42000,
            "path" => $cookie_params["path"] ?? "/",
            "domain" => $cookie_params["domain"] ?? "",
            "secure" => (bool) ($cookie_params["secure"] ?? false),
            "httponly" => (bool) ($cookie_params["httponly"] ?? true),
            "samesite" => $cookie_params["samesite"] ?? "Lax"
        ]
    );
}

session_destroy();

header("Location: login.php");
exit;

?>
