<?php

session_start();

require_once 'config/database.php';


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

                // Oturum sabitleme (session fixation) saldırılarına karşı
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

?>

<!DOCTYPE html>
<html lang="tr">

<head>

    <meta charset="UTF-8">

    <title>Todo App - Giriş</title>

    <link rel="stylesheet" href="css/style.css">
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

            <button type="submit" class="full-width">
                Giriş Yap
            </button>

        </form>

    </div>

</div>

</body>

</html>