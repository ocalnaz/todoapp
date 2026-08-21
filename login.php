<?php

require_once __DIR__ . "/config/session.php";

require_once 'config/database.php';

$recaptcha_secret = trim(
    (string) (getenv("TODOAPP_RECAPTCHA_SECRET") ?: "")
);

define('MAX_LOGIN_FAILURES', 3);
define('LOGIN_LOCK_SECONDS', 15 * 60);
define('MAX_LOGIN_LOCK_LEVEL', 3);

const LOGIN_LOCK_LEVEL_SECONDS = [
    15 * 60,
    30 * 60,
    60 * 60
];

/**
 * İstekten güvenilir biçimde istemci IP adresini alır.
 * X-Forwarded-For gibi kullanıcı tarafından taklit edilebilen başlıklar
 * kullanılmaz; doğrudan sunucunun REMOTE_ADDR değeri esas alınır.
 */
function get_client_ip_address(): string
{
    $remote_address = trim(
        (string) ($_SERVER['REMOTE_ADDR'] ?? '')
    );

    return filter_var(
        $remote_address,
        FILTER_VALIDATE_IP
    ) ? substr($remote_address, 0, 45) : '';
}

/**
 * Kilit süresini kullanıcıya okunabilir biçimde gösterir.
 */
function format_lock_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    $minutes = intdiv($seconds, 60);
    $remaining_seconds = $seconds % 60;

    if ($minutes > 0) {
        return $minutes . ' dakika ' . $remaining_seconds . ' saniye';
    }

    return $remaining_seconds . ' saniye';
}

/**
 * Giriş olaylarını SQLite üzerindeki login_logs tablosuna kaydeder.
 */
function log_login_event(
    PDO $db,
    ?int $user_id,
    string $username,
    string $event_type
): void {
    $ip_address = get_client_ip_address();

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
function get_login_attempt(
    PDO $db,
    string $username,
    string $ip_address
): ?array {
    $stmt = $db->prepare("
        SELECT
            username,
            ip_address,
            failed_attempts,
            first_failed_at,
            last_failed_at,
            locked_until,
            lock_level,
            last_lock_seconds
        FROM login_attempts
        WHERE username = ?
          AND ip_address = ?
        LIMIT 1
    ");

    $stmt->execute([
        $username,
        $ip_address
    ]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

    return $attempt ?: null;
}

/**
 * Başarılı girişten veya süresi dolmuş kilitten sonra sayacı temizler.
 */
function clear_login_attempt(
    PDO $db,
    string $username,
    string $ip_address
): void {
    $stmt = $db->prepare(
        "DELETE FROM login_attempts "
        . "WHERE username = ? AND ip_address = ?"
    );

    $stmt->execute([
        $username,
        $ip_address
    ]);
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
function register_failed_login(
    PDO $db,
    string $username,
    string $ip_address
): array {
    $now_timestamp = time();
    $now_database = gmdate('Y-m-d H:i:s');
    $attempt = get_login_attempt(
        $db,
        $username,
        $ip_address
    );

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

    $lock_level = (int) ($attempt['lock_level'] ?? 0);
    $lock_seconds = 0;
    $locked_until = null;

    if ($failed_attempts >= MAX_LOGIN_FAILURES) {
        $lock_level = min(
            $lock_level + 1,
            MAX_LOGIN_LOCK_LEVEL
        );

        $lock_seconds = LOGIN_LOCK_LEVEL_SECONDS[
            $lock_level - 1
        ] ?? LOGIN_LOCK_SECONDS;

        $locked_until = gmdate(
            'Y-m-d H:i:s',
            $now_timestamp + $lock_seconds
        );
    }

    if ($attempt) {
        $stmt = $db->prepare("
            UPDATE login_attempts
            SET
                failed_attempts = ?,
                first_failed_at = ?,
                last_failed_at = ?,
                locked_until = ?,
                lock_level = ?,
                last_lock_seconds = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE username = ?
              AND ip_address = ?
        ");

        $stmt->execute([
            $failed_attempts,
            $first_failed_at,
            $now_database,
            $locked_until,
            $lock_level,
            $lock_seconds,
            $username,
            $ip_address
        ]);
    } else {
        $stmt = $db->prepare("
            INSERT INTO login_attempts
            (
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
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");

        $stmt->execute([
            $username,
            $ip_address,
            $failed_attempts,
            $first_failed_at,
            $now_database,
            $locked_until,
            $lock_level,
            $lock_seconds
        ]);
    }

    return [
        'failed_attempts' => $failed_attempts,
        'locked_until' => $locked_until,
        'lock_level' => $lock_level,
        'lock_seconds' => $lock_seconds
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

if (empty($_SESSION["login_csrf_token"])) {
    $_SESSION["login_csrf_token"] = bin2hex(random_bytes(32));
}

$login_csrf_token = (string) $_SESSION["login_csrf_token"];
$error = "";
$lock_remaining_seconds = 0;

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim((string) ($_POST["username"] ?? ""));
    $password = (string) ($_POST["password"] ?? "");
    $client_ip = get_client_ip_address();
    $recaptcha = (string) ($_POST["g-recaptcha-response"] ?? "");
    $submitted_csrf = (string) ($_POST["csrf_token"] ?? "");

    if (
        !hash_equals($login_csrf_token, $submitted_csrf)
    ) {
        $error = "Geçersiz veya süresi dolmuş istek. Lütfen sayfayı yenileyip tekrar deneyin.";

        log_login_event(
            $db,
            null,
            $username !== "" ? $username : "[boş]",
            "csrf_failed"
        );
    }

    // ==================================================
    // RECAPTCHA KONTROLÜ
    // ==================================================

    if ($recaptcha_secret === "") {

        $error = "Sunucu reCAPTCHA ayarı eksik. Lütfen yöneticinizle iletişime geçin.";

        log_login_event(
            $db,
            null,
            $username !== "" ? $username : "[boş]",
            "recaptcha_config_missing"
        );

    } elseif (empty($recaptcha)) {

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

            $attempt = get_login_attempt(
                $db,
                $username,
                $client_ip
            );
            $locked_until_timestamp = utc_database_time_to_timestamp(
                $attempt["locked_until"] ?? null
            );

            if (
                $locked_until_timestamp !== null
                && $locked_until_timestamp > time()
            ) {

                $lock_remaining_seconds = max(
                    0,
                    $locked_until_timestamp - time()
                );

                log_login_event(
                    $db,
                    null,
                    $username,
                    "login_blocked"
                );

                $error = "Çok fazla hatalı deneme yapıldı. "
                    . format_lock_duration($lock_remaining_seconds)
                    . " sonra tekrar deneyin.";

            } else {

                // Süresi dolmuş kilitte lock_level korunur; yeni pencere
                // başladığında başarısız deneme sayısı register_failed_login()
                // içinde yeniden 1 olarak hesaplanır.

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

                        clear_login_attempt(
                            $db,
                            $username,
                            $client_ip
                        );

                        log_login_event(
                            $db,
                            (int) $user["id"],
                            $username,
                            "invalid_role"
                        );

                        $error = "Hesabınızın rolü tanımsız. "
                            . "Lütfen yönetici ile iletişime geçin.";

                    } else {

                        clear_login_attempt(
                            $db,
                            $username,
                            $client_ip
                        );

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
                        $username,
                        $client_ip
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

                        $lock_event_type = match (
                            (int) ($failure["lock_level"] ?? 0)
                        ) {
                            1 => "login_locked_15m",
                            2 => "login_locked_30m",
                            3 => "login_locked_60m",
                            default => "login_locked"
                        };

                        log_login_event(
                            $db,
                            $log_user_id,
                            $username,
                            $lock_event_type
                        );

                        $lock_remaining_seconds = max(
                            0,
                            utc_database_time_to_timestamp(
                                $failure["locked_until"] ?? null
                            ) - time()
                        );

                        $error = "3 hatalı deneme yapıldı. "
                            . format_lock_duration($lock_remaining_seconds)
                            . " sonra tekrar deneyin.";

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

            <div
                class="error"
                role="alert"
            >
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>

                <?php if ($lock_remaining_seconds > 0): ?>

                    <div
                        id="lockoutCountdown"
                        class="lockout-countdown"
                        data-remaining-seconds="<?= (int) $lock_remaining_seconds ?>"
                        aria-live="polite"
                    >
                        Kalan süre hesaplanıyor...
                    </div>

                <?php endif; ?>
            </div>

        <?php endif; ?>


        <form method="POST" id="loginForm">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $login_csrf_token,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>"
            >

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


            <button
                type="submit"
                class="full-width"
                id="loginSubmitButton"
                <?= $lock_remaining_seconds > 0 ? "disabled" : "" ?>
            >
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

<?php if ($lock_remaining_seconds > 0): ?>
<script>
(function () {
    const countdown = document.getElementById("lockoutCountdown");
    const submitButton = document.getElementById("loginSubmitButton");

    if (!countdown) {
        return;
    }

    let remaining = Math.max(
        0,
        Number(countdown.dataset.remainingSeconds || 0)
    );

    function renderCountdown() {
        const minutes = Math.floor(remaining / 60);
        const seconds = remaining % 60;
        const formattedSeconds = String(seconds).padStart(2, "0");

        if (remaining > 0) {
            countdown.textContent =
                "Canlı kalan süre: "
                + minutes
                + ":"
                + formattedSeconds;

            if (submitButton) {
                submitButton.disabled = true;
            }
        } else {
            countdown.textContent =
                "Kilit süresi doldu. Tekrar deneyebilirsiniz.";

            if (submitButton) {
                submitButton.disabled = false;
            }
        }
    }

    renderCountdown();

    const timer = window.setInterval(function () {
        remaining = Math.max(0, remaining - 1);
        renderCountdown();

        if (remaining === 0) {
            window.clearInterval(timer);
        }
    }, 1000);
})();
</script>
<?php endif; ?>

</body>

</html>
