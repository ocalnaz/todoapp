<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . "/../config/session.php";
}

require_once "../config/database.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if (
    !isset($_SESSION["role"])
    || $_SESSION["role"] !== "admin"
) {
    echo "Bu sayfaya erişim yetkiniz yok.";
    exit;
}

function format_turkey_datetime(?string $value): string
{
    if (empty($value)) {
        return "-";
    }

    try {
        $date = new DateTime(
            $value,
            new DateTimeZone("UTC")
        );

        $date->setTimezone(
            new DateTimeZone("Europe/Istanbul")
        );

        return $date->format("d.m.Y H:i:s");

    } catch (Exception $e) {

        return htmlspecialchars(
            $value,
            ENT_QUOTES,
            "UTF-8"
        );
    }
}

function login_event_label(string $event_type): array
{
    $labels = [

        "login_success" => [
            "label" => "Başarılı giriş",
            "class" => "onaylandi"
        ],

        "login_failed" => [
            "label" => "Başarısız giriş",
            "class" => "revize"
        ],

        "login_locked" => [
            "label" => "Hesap kilitlendi",
            "class" => "expired"
        ],

        "login_locked_15m" => [
            "label" => "15 dakika kilitlendi",
            "class" => "expired"
        ],

        "login_locked_30m" => [
            "label" => "30 dakika kilitlendi",
            "class" => "expired"
        ],

        "login_locked_60m" => [
            "label" => "60 dakika kilitlendi",
            "class" => "expired"
        ],

        "login_blocked" => [
            "label" => "Kilitliyken deneme",
            "class" => "expired"
        ],

        "logout" => [
            "label" => "Çıkış yapıldı",
            "class" => "bekliyor"
        ],

        "validation_failed" => [
            "label" => "Eksik bilgi",
            "class" => "bekliyor"
        ],

        "recaptcha_failed" => [
            "label" => "reCAPTCHA başarısız",
            "class" => "revize"
        ],

        "recaptcha_config_missing" => [
            "label" => "reCAPTCHA ayarı eksik",
            "class" => "expired"
        ],

        "csrf_failed" => [
            "label" => "CSRF doğrulaması başarısız",
            "class" => "expired"
        ],

        "invalid_role" => [
            "label" => "Geçersiz rol",
            "class" => "expired"
        ]
    ];

    return $labels[$event_type] ?? [
        "label" => $event_type,
        "class" => "incelemede"
    ];
}


$stmt = $db->query("
    SELECT
        login_logs.id,
        login_logs.user_id,
        login_logs.username,
        login_logs.event_type,
        login_logs.ip_address,
        login_logs.user_agent,
        login_logs.created_at,
        users.full_name
    FROM login_logs
    LEFT JOIN users
        ON login_logs.user_id = users.id
    ORDER BY login_logs.id DESC
    LIMIT 500
");

$login_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="tr">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Giriş Logları - Todo App</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css?v=upload-card-lavanta-20260819"
    >

</head>


<body>


<!-- ==================================================
     SIDEBAR
================================================== -->

<div class="sidebar">

    <h2>
        TODO APP
    </h2>


    <div class="sidebar-profile-summary">

        <strong class="sidebar-profile-name">

            <?= htmlspecialchars(
                $_SESSION["full_name"] ?? "Admin",
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </strong>

        <span class="sidebar-profile-role">
            Yönetici
        </span>

    </div>


    <nav
        class="sidebar-navigation"
        aria-label="Yönetici menüsü"
    >

        <a
            href="dashboard.php#dashboard"
            class="sidebar-page-link"
        >

            <span aria-hidden="true">
                📊
            </span>

            Dashboard

        </a>


        <a
            href="dashboard.php#kullanicilar"
            class="sidebar-page-link"
        >

            <span aria-hidden="true">
                👥
            </span>

            Kullanıcılar

        </a>


        <a
            href="dashboard.php#gorevler"
            class="sidebar-page-link"
        >

            <span aria-hidden="true">
                📋
            </span>

            Görevler

        </a>


        <a
            href="arsiv.php"
            class="sidebar-page-link"
        >
            <span aria-hidden="true">🗃️</span>
            Görev Arşivi
        </a>

        <a
            href="raporlar.php"
            class="sidebar-page-link"
        >
            <span aria-hidden="true">📈</span>
            Rapor Dışa Aktar
        </a>

        <a
            href="dashboard.php#calismalar"
            class="sidebar-page-link"
        >

            <span aria-hidden="true">
                📤
            </span>

            Görev Çalışmaları

        </a>


        <a
            href="giris_loglari.php"
            class="sidebar-page-link active"
            aria-current="page"
        >

            <span aria-hidden="true">
                🧾
            </span>

            Giriş Logları

        </a>

    </nav>


    <a
        href="../logout.php"
        class="logout"
    >

        <span aria-hidden="true">
            🚪
        </span>

        Çıkış Yap

    </a>

</div>


<!-- ==================================================
     ANA İÇERİK
================================================== -->

<main class="main">

    <div class="container">


        <div class="section-title">

            <h1>
                🧾 Giriş Logları
            </h1>

            <p>

                Başarılı giriş, hatalı şifre,
                kilitlenme ve çıkış hareketleri.

                Son 500 kayıt gösteriliyor.

            </p>

        </div>


        <div class="box">


            <?php if (empty($login_logs)): ?>


                <div class="empty">

                    <h2>
                        Henüz giriş hareketi yok.
                    </h2>

                    <p>
                        Giriş ve çıkış hareketleri
                        oluştukça burada görünecek.
                    </p>

                </div>


            <?php else: ?>


                <div class="login-log-table-wrapper">

                    <table class="login-log-table">

                        <thead>

                            <tr>

                                <th>
                                    Tarih
                                </th>

                                <th>
                                    Kullanıcı
                                </th>

                                <th>
                                    Olay
                                </th>

                                <th>
                                    IP adresi
                                </th>

                                <th>
                                    Tarayıcı
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                            <?php foreach ($login_logs as $log): ?>


                                <?php

                                $event = login_event_label(
                                    (string) (
                                        $log["event_type"] ?? ""
                                    )
                                );

                                ?>


                                <tr>


                                    <td>

                                        <?= format_turkey_datetime(
                                            $log["created_at"] ?? null
                                        ) ?>

                                    </td>


                                    <td>

                                        <strong>

                                            <?= htmlspecialchars(
                                                $log["full_name"]
                                                    ?: $log["username"],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>

                                        </strong>


                                        <span
                                            class="login-log-username"
                                        >

                                            @<?= htmlspecialchars(
                                                $log["username"],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>

                                        </span>

                                    </td>


                                    <td>

                                        <span
                                            class="status <?= htmlspecialchars(
                                                $event["class"],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>"
                                        >

                                            <?= htmlspecialchars(
                                                $event["label"],
                                                ENT_QUOTES,
                                                "UTF-8"
                                            ) ?>

                                        </span>

                                    </td>


                                    <td>

                                        <?= htmlspecialchars(
                                            $log["ip_address"] ?? "-",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </td>


                                    <td
                                        class="login-log-user-agent"
                                    >

                                        <?= htmlspecialchars(
                                            $log["user_agent"] ?? "-",
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>

                                    </td>


                                </tr>


                            <?php endforeach; ?>


                        </tbody>

                    </table>

                </div>


            <?php endif; ?>


        </div>

    </div>

</main>


<!-- ==================================================
     TEMA BUTONU
================================================== -->

<button
    class="theme-toggle"
    id="themeToggle"
    title="Tema değiştir"
    type="button"
>
    🌙
</button>


<!-- ==================================================
     TEMA JAVASCRIPT
================================================== -->

<script>

// ==================================================
// TEMA
// ==================================================

const themeToggle =
    document.getElementById(
        "themeToggle"
    );


if (
    localStorage.getItem("theme") === "dark"
) {

    document.body.classList.add(
        "dark-mode"
    );

    themeToggle.textContent =
        "☀️";

}


themeToggle.addEventListener(
    "click",
    function () {

        document.body.classList.toggle(
            "dark-mode"
        );


        if (
            document.body.classList.contains(
                "dark-mode"
            )
        ) {

            themeToggle.textContent =
                "☀️";

            localStorage.setItem(
                "theme",
                "dark"
            );

        } else {

            themeToggle.textContent =
                "🌙";

            localStorage.setItem(
                "theme",
                "light"
            );

        }

    }
);

</script>


</body>

</html>