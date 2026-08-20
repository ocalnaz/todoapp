<?php

session_start();

require_once 'config/database.php';

$recaptcha_secret = "6LeliI0tAAAAALA3miSCT9KnE0jdFTAJPn76g5Vp";

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

    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    $recaptcha = $_POST["g-recaptcha-response"] ?? "";


    // ==================================================
    // RECAPTCHA KONTROLÜ
    // ==================================================

    if (empty($recaptcha)) {

        $error = "Lütfen robot olmadığınızı doğrulayın.";

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
                "content" => http_build_query($verify_data)
            ]
        ];

        $context = stream_context_create($options);

        $response = file_get_contents(
            $verify_url,
            false,
            $context
        );

        $response_data = json_decode($response, true);


        if (
            !$response_data ||
            empty($response_data["success"])
        ) {

            $error = "Robot doğrulaması başarısız oldu. Lütfen tekrar deneyin.";

        }

    }


    // ==================================================
    // KULLANICI ADI + ŞİFRE KONTROLÜ
    // ==================================================

    if (empty($error)) {

        if (empty($username) || empty($password)) {

            $error = "Kullanıcı adı ve şifre boş bırakılamaz.";

        } else {

            $stmt = $db->prepare("
                SELECT * FROM users
                WHERE username = ?
            ");

            $stmt->execute([$username]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);


            if ($user && password_verify($password, $user["password"])) {

                // MATRİS / DİZİ: Kullanıcı rolünü sayfayla eşleştiriyoruz
                $pages = [
                    "admin" => "admin/dashboard.php",
                    "user" => "user/dashboard.php"
                ];


                // Rol tanımlı değilse yönlendirme yapmadan hata göster
                if (!isset($pages[$user["role"]])) {

                    $error = "Hesabınızın rolü tanımsız. Lütfen yönetici ile iletişime geçin.";

                } else {

                    // Oturum sabitleme saldırılarına karşı
                    // giriş sonrası oturum kimliğini yeniden oluştur
                    session_regenerate_id(true);

                    $_SESSION["user_id"] = $user["id"];
                    $_SESSION["username"] = $user["username"];
                    $_SESSION["full_name"] = $user["full_name"];
                    $_SESSION["role"] = $user["role"];

                    header("Location: " . $pages[$user["role"]]);
                    exit;
                }

            } else {

                $error = "Kullanıcı adı veya şifre hatalı.";

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
                <?= htmlspecialchars($error) ?>
            </div>

        <?php endif; ?>


        <form method="POST">

            <label>Kullanıcı Adı</label>

            <input
                type="text"
                name="username"
                placeholder="Kullanıcı adınızı girin"
            >


            <label>Şifre</label>

            <input
                type="password"
                name="password"
                placeholder="Şifrenizi girin"
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