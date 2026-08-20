<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once 'config/database.php';

$recaptcha_secret = "6LeliI0tAAAAALA3miSCT9KnE0jdFTAJPn76g5Vp";

define('MAX_LOGIN_FAILURES', 3);
define('LOGIN_LOCK_SECONDS', 15 * 60);

/**
 * Giriş olaylarını SQLite üzerindeki login_logs tablosuna kaydeder.
 */
function log_login_event(
    PDO $db,
    ?int $user_id,
    string $username,
    string $event_type
): void {
    $ip_address = substr(
        (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        0,
        45
    );

    $user_agent = substr(
        (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        0,
        500
    );

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
        $event_type,
        $ip_address !== '' ? $ip_address : null,
        $user_agent !== '' ? $user_agent : null
    ]);
}

/**
 * Kullanıcı adının başarısız giriş/kilit kaydını getirir.
 */
function get_login_attempt(PDO $db, string $username): ?array {
    $stmt = $db->prepare("
        SELECT
            username,
            failed_attempts,
            first_failed_at,
            last_failed_at,
            locked_until
        FROM login_attempts
        WHERE username = ?
        LIMIT 1
    ");

    $stmt->execute([$username]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

    return $attempt ?: null;
}

/**
 * Başarılı girişten veya süresi dolmuş kilitten sonra sayacı temizler.
 */
function clear_login_attempt(PDO $db, string $username): void {
    $stmt = $db->prepare(
        "DELETE FROM login_attempts WHERE username = ?"
    );

    $stmt->execute([$username]);
}

/**
 * SQLite CURRENT_TIMESTAMP değerini UTC Unix zamanına çevirir.
 */
function utc_database_time_to_timestamp(?string $value): ?int {
    if (empty($value)) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat(
        '!Y-m-d H:i:s',
        $value,
        new DateTimeZone('UTC')
    );

    return $date instanceof DateTimeImmutable
        ? $date->getTimestamp()
        : null;
}

/**
 * Başarısız şifre denemesini kaydeder ve güncel sayacı döndürür.
 */
function register_failed_login(PDO $db, string $username): array {
    $now_timestamp = time();
    $now_database = gmdate('Y-m-d H:i:s');
    $attempt = get_login_attempt($db, $username);

    $last_failed_timestamp = utc_database_time_to_timestamp(
        $attempt['last_failed_at'] ?? null
    );

    $window_is_active = $last_failed_timestamp !== null
        && ($now_timestamp - $last_failed_timestamp) < LOGIN_LOCK_SECONDS;

    $failed_attempts = $window_is_active
        ? ((int) ($attempt['failed_attempts'] ?? 0) + 1)
        : 1;

    $first_failed_at = $window_is_active
        ? (string) ($attempt['first_failed_at'] ?? $now_database)
        : $now_database;

    $locked_until = $failed_attempts >= MAX_LOGIN_FAILURES
        ? gmdate(
            'Y-m-d H:i:s',
            $now_timestamp + LOGIN_LOCK_SECONDS
        )
        : null;

    if ($attempt) {
        $stmt = $db->prepare("
            UPDATE login_attempts
            SET
                failed_attempts = ?,
                first_failed_at = ?,
                last_failed_at = ?,
                locked_until = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE username = ?
        ");

        $stmt->execute([
            $failed_attempts,
            $first_failed_at,
            $now_database,
            $locked_until,
            $username
        ]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO login_attempts
            (
                username,
                failed_attempts,
                first_failed_at,
                last_failed_at,
                locked_until,
                updated_at
            )
            VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");

        $stmt->execute([
            $username,
            $failed_attempts,
            $first_failed_at,
            $now_database,
            $locked_until
        ]);
    }

    return [
        'failed_attempts' => $failed_attempts,
        'locked_until' => $locked_until
    ];
}

// Zaten giriş yapmış kullanıcıyı kendi panosuna gönder
if (isset($_SESSION["user_id"])) {

    $pages = [
        "admin" => "admin/dashboard.php",
        "user" => "user/dashboard.php"
    ];

    $target = $pages[$_SESSION["role"]] ?? null;

    if ($target) {
        header("Location: " . $target);
        exit;
    }
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim((string) ($_POST["username"] ?? ""));
    $password = (string) ($_POST["password"] ?? "");
    $recaptcha = (string) ($_POST["g-recaptcha-response"] ?? "");

    // ==================================================
    // RECAPTCHA KONTROLÜ
    // ==================================================

    if (empty($recaptcha)) {

        $error = "Lütfen robot olmadığınızı doğrulayın.";

        log_login_event(
            $db,
            null,
            $username !== "" ? $username : "[boş]",
            "recaptcha_failed"
        );

    } else {

        $verify_url = "https://www.google.com/recaptcha/api/siteverify";

        $verify_data = [
            "secret"   => $recaptcha_secret,
            "response" => $recaptcha,
            "remoteip" => $_SERVER["REMOTE_ADDR"] ?? ""
        ];

        $options = [
            "http" => [
                "header"  => "Content-type: application/x-www-form-urlencoded\r\n",
                "method"  => "POST",
                "content" => http_build_query($verify_data),
                "timeout" => 10
            ]
        ];

        $context = stream_context_create($options);

        $response = @file_get_contents(
            $verify_url,
            false,
            $context
        );

        $response_data = json_decode(
            (string) $response,
            true
        );

        if (
            !$response_data
            || empty($response_data["success"])
        ) {

            $error = "Robot doğrulaması başarısız oldu. Lütfen tekrar deneyin.";

            log_login_event(
                $db,
                null,
                $username !== "" ? $username : "[boş]",
                "recaptcha_failed"
            );
        }
    }

    // ==================================================
    // KULLANICI ADI + ŞİFRE KONTROLÜ
    // ==================================================

    if (empty($error)) {

        if (empty($username) || empty($password)) {

            $error = "Kullanıcı adı ve şifre boş bırakılamaz.";

            log_login_event(
                $db,
                null,
                $username !== "" ? $username : "[boş]",
                "validation_failed"
            );

        } else {

            $attempt = get_login_attempt($db, $username);
            $locked_until_timestamp = utc_database_time_to_timestamp(
                $attempt["locked_until"] ?? null
            );

            if (
                $locked_until_timestamp !== null
                && $locked_until_timestamp > time()
            ) {

                $remaining_minutes = (int) ceil(
                    ($locked_until_timestamp - time()) / 60
                );

                log_login_event(
                    $db,
                    null,
                    $username,
                    "login_blocked"
                );

                $error = "Çok fazla hatalı deneme yapıldı. "
                    . $remaining_minutes
                    . " dakika sonra tekrar deneyin.";

            } else {

                // Süresi dolmuş kilit varsa yeni deneme temiz bir sayaçla başlar.
                if ($attempt && !empty($attempt["locked_until"])) {
                    clear_login_attempt($db, $username);
                }

                $stmt = $db->prepare("
                    SELECT
                        id,
                        full_name,
                        username,
                        password,
                        role
                    FROM users
                    WHERE username = ?
                    LIMIT 1
                ");

                $stmt->execute([$username]);

                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (
                    $user
                    && password_verify(
                        $password,
                        $user["password"]
                    )
                ) {

                    $pages = [
                        "admin" => "admin/dashboard.php",
                        "user" => "user/dashboard.php"
                    ];

                    // Rol tanımlı değilse yönlendirme yapmadan hata göster.
                    if (!isset($pages[$user["role"]])) {

                        clear_login_attempt($db, $username);

                        log_login_event(
                            $db,
                            (int) $user["id"],
                            $username,
                            "invalid_role"
                        );

                        $error = "Hesabınızın rolü tanımsız. "
                            . "Lütfen yönetici ile iletişime geçin.";

                    } else {

                        clear_login_attempt($db, $username);

                        // Oturum sabitleme saldırılarına karşı
                        // giriş sonrası oturum kimliğini yeniden oluştur.
                        session_regenerate_id(true);

                        $_SESSION["user_id"] = (int) $user["id"];
                        $_SESSION["username"] = $user["username"];
                        $_SESSION["full_name"] = $user["full_name"];
                        $_SESSION["role"] = $user["role"];

                        log_login_event(
                            $db,
                            (int) $user["id"],
                            $username,
                            "login_success"
                        );

                        header("Location: " . $pages[$user["role"]]);
                        exit;
                    }

                } else {

                    $failure = register_failed_login(
                        $db,
                        $username
                    );

                    $log_user_id = $user
                        ? (int) $user["id"]
                        : null;

                    log_login_event(
                        $db,
                        $log_user_id,
                        $username,
                        "login_failed"
                    );

                    if (
                        $failure["failed_attempts"]
                        >= MAX_LOGIN_FAILURES
                    ) {

                        log_login_event(
                            $db,
                            $log_user_id,
                            $username,
                            "login_locked"
                        );

                        $error = "3 hatalı deneme yapıldı. "
                            . "15 dakika sonra tekrar deneyin.";

                    } else {

                        $error = "Kullanıcı adı veya şifre hatalı.";
                    }
                }
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="tr">

<head>

    <meta charset="UTF-8">

    <title>Todo App - Giriş</title>

    <link rel="stylesheet" href="assets/css/style.css">

</head>

<body>

<div class="login-wrapper">

    <div class="login-box">

        <h1>Todo App</h1>

        <p class="subtitle">
            Görevlerini kolayca yönet
        </p>


        <?php if (!empty($error)): ?>

            <div class="error">
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </div>

        <?php endif; ?>


        <form method="POST">

            <label>Kullanıcı Adı</label>

            <input
                type="text"
                name="username"
                placeholder="Kullanıcı adınızı girin"
                value="<?= htmlspecialchars(
                    $username ?? "",
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>"
                autocomplete="username"
            >


            <label>Şifre</label>

            <input
                type="password"
                name="password"
                placeholder="Şifrenizi girin"
                autocomplete="current-password"
            >


            <!-- RECAPTCHA -->
            <div
                class="g-recaptcha"
                data-sitekey="6LeliI0tAAAAAFdp_f-WRv0uH689THhMqXLqZjW-">
            </div>


            <button type="submit" class="full-width">
                Giriş Yap
            </button>

        </form>

    </div>

</div>


<script
    src="https://www.google.com/recaptcha/api.js"
    async
    defer>
</script>

</body>

</html>
