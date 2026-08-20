<?php

require_once __DIR__ . "/../config/session.php";

require_once "../config/database.php";


// ==================================================
// GİRİŞ KONTROLÜ
// ==================================================

if (!isset($_SESSION["user_id"])) {

    header("Location: ../login.php");
    exit;
}


// ==================================================
// ADMIN KONTROLÜ
// ==================================================

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {

    echo "Bu sayfaya erişim yetkiniz yok.";
    exit;
}

$admin_id = (int) ($_SESSION["user_id"] ?? 0);


// ==================================================
// CSRF TOKEN
// ==================================================

if (empty($_SESSION["csrf_token"])) {

    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


$message = "";
$error = "";


// ==================================================
// KULLANICI SİLME
// ==================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["delete_id"])
) {

    if (
        !isset($_POST["csrf_token"])
        || !hash_equals(
            $_SESSION["csrf_token"],
            $_POST["csrf_token"]
        )
    ) {

        $error = "Geçersiz istek. Lütfen sayfayı yenileyip tekrar deneyin.";

    } else {

        $delete_id = filter_var(
            $_POST["delete_id"],
            FILTER_VALIDATE_INT,
            ["options" => ["min_range" => 1]]
        );

        // Kendi hesabını veya başka bir admin hesabını silmesini engelle.
        if ($delete_id === false) {

            $error = "Geçersiz kullanıcı.";

        } elseif ($delete_id === $admin_id) {

            $error = "Kendi hesabınızı silemezsiniz.";

        } else {

            $target_stmt = $db->prepare("
                SELECT id
                FROM users
                WHERE id = ?
                  AND role = 'user'
                LIMIT 1
            ");
            $target_stmt->execute([$delete_id]);

            if (!$target_stmt->fetch(PDO::FETCH_ASSOC)) {

                $error = "Bu kullanıcıyı silme yetkiniz yok.";

            } else {

                try {

                $db->beginTransaction();


                // Kullanıcının görev gönderilerini sil
                $stmt = $db->prepare("
                    DELETE FROM task_submissions
                    WHERE user_id = ?
                ");

                $stmt->execute([$delete_id]);


                // Kullanıcının görevlerini sil
                $stmt = $db->prepare("
                    DELETE FROM tasks
                    WHERE assigned_to = ?
                ");

                $stmt->execute([$delete_id]);


                // Kullanıcının kendi çalışmalarını sil
                $stmt = $db->prepare("
                    DELETE FROM user_activities
                    WHERE user_id = ?
                ");

                $stmt->execute([$delete_id]);


                // Kullanıcıyı sil
                $stmt = $db->prepare("
                    DELETE FROM users
                    WHERE id = ?
                ");

                    $stmt->execute([$delete_id]);

                    $db->commit();

                    header("Location: kullanicilar.php");
                    exit;

                } catch (PDOException $e) {

                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }

                    $error = "Kullanıcı silinirken hata oluştu.";
                }
            }
        }
    }
}


// ==================================================
// YENİ KULLANICI EKLEME
// ==================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && !isset($_POST["delete_id"])
) {

    if (
        !isset($_POST["csrf_token"])
        || !hash_equals(
            $_SESSION["csrf_token"],
            $_POST["csrf_token"]
        )
    ) {

        $error = "Geçersiz istek. Lütfen sayfayı yenileyip tekrar deneyin.";

    } else {

        $full_name = trim($_POST["full_name"] ?? "");
        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";


        if (
            $full_name === ""
            || $username === ""
            || $password === ""
        ) {

            $error = "Tüm alanları doldurmalısınız.";

        } elseif (
            mb_strlen($full_name) > 120
            || mb_strlen($username) > 80
            || !preg_match('/^[A-Za-z0-9_.-]+$/', $username)
        ) {

            $error = "Ad, kullanıcı adı veya kullanıcı adı biçimi geçersiz.";

        } elseif (strlen($password) < 8) {

            $error = "Şifre en az 8 karakter olmalıdır.";

        } else {

            try {

                $hashed_password = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );


                $stmt = $db->prepare("
                    INSERT INTO users
                    (
                        username,
                        password,
                        full_name,
                        role
                    )
                    VALUES (?, ?, ?, ?)
                ");


                $stmt->execute([
                    $username,
                    $hashed_password,
                    $full_name,
                    "user"
                ]);


                $message = "Kullanıcı başarıyla oluşturuldu.";


            } catch (PDOException $e) {

                $error = "Bu kullanıcı adı zaten kullanılıyor.";
            }
        }
    }
}


// ==================================================
// KULLANICILARI GETİR
// ==================================================

$stmt = $db->query("
    SELECT
        id,
        username,
        full_name,
        role,
        created_at
    FROM users
    ORDER BY id DESC
");


$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>

<html lang="tr">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Kullanıcı Yönetimi - Todo App</title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css"
    >

</head>


<body>


<!-- ==================================================
     SOL MENÜ
================================================== -->

<div class="sidebar">

    <h2>Todo App</h2>


    <a href="dashboard.php">

        🏠 Dashboard

    </a>


    <a
        href="kullanicilar.php"
        class="active"
    >

        🧒🏻👩🏻 Kullanıcılar

    </a>


    <a href="gorevler.php">

        📒 Görevler

    </a>


    <a href="gonderilenler.php">

        📤 Gönderilenler

    </a>


    <a href="calismalar.php">

        📊 Kullanıcı Çalışmaları

    </a>


    <a
        href="../logout.php"
        class="logout"
    >

        🚪 Çıkış Yap

    </a>

</div>


<!-- ==================================================
     ANA İÇERİK
================================================== -->

<div class="main">

    <div class="container">


        <!-- BAŞLIK -->

        <div class="page-header">

            <h1>
                Kullanıcı Yönetimi
            </h1>

            <p>
                Sistemdeki kullanıcıları yönetin ve yeni kullanıcı oluşturun.
            </p>

        </div>


        <!-- MESAJLAR -->

        <?php if (!empty($message)): ?>

            <div class="success">

                <?= htmlspecialchars($message) ?>

            </div>

        <?php endif; ?>


        <?php if (!empty($error)): ?>

            <div class="error">

                <?= htmlspecialchars($error) ?>

            </div>

        <?php endif; ?>


        <!-- ==================================================
             YENİ KULLANICI
        ================================================== -->

        <div class="box">

            <h2>
                Yeni Kullanıcı Oluştur
            </h2>


            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
                >


                <label>
                    Ad Soyad
                </label>


                <input
                    type="text"
                    name="full_name"
                    placeholder="Örneğin: Ahmet Yılmaz"
                >


                <label>
                    Kullanıcı Adı
                </label>


                <input
                    type="text"
                    name="username"
                    placeholder="Örneğin: ahmet"
                >


                <label>
                    Şifre
                </label>


                <input
                    type="password"
                    name="password"
                    placeholder="Şifre"
                >


                <button
                    type="submit"
                    class="full-width"
                >

                    Kullanıcı Oluştur

                </button>

            </form>

        </div>


        <!-- ==================================================
             KULLANICI LİSTESİ
        ================================================== -->

        <div class="box">

            <h2>
                Mevcut Kullanıcılar
            </h2>


            <table>

                <tr>

                    <th>No</th>

                    <th>Ad Soyad</th>

                    <th>Kullanıcı Adı</th>

                    <th>Rol</th>

                    <th>Oluşturulma</th>

                    <th>İşlem</th>

                </tr>


                <?php foreach ($users as $index => $user): ?>

                    <tr>


                        <td>

                            <?= $index + 1 ?>

                        </td>


                        <td>

                            <?= htmlspecialchars(
                                $user["full_name"]
                            ) ?>

                        </td>


                        <td>

                            <?= htmlspecialchars(
                                $user["username"]
                            ) ?>

                        </td>


                        <td>

                            <span class="role">

                                <?= htmlspecialchars(
                                    $user["role"]
                                ) ?>

                            </span>

                        </td>


                        <td>

                            <?= htmlspecialchars(
                                $user["created_at"]
                            ) ?>

                        </td>


                        <td>

                            <div class="table-actions">


                                <!-- DÜZENLE -->

                                <a
                                    href="kullanici_duzenle.php?id=<?= (int) $user["id"] ?>"
                                    class="edit-button"
                                >

                                    ✏️ Düzenle

                                </a>


                                <!-- SİL -->

                                <form method="POST">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
                                    >


                                    <input
                                        type="hidden"
                                        name="delete_id"
                                        value="<?= (int) $user["id"] ?>"
                                    >


                                    <button
                                        type="submit"
                                        class="delete-button"
                                        onclick="return confirm('Bu kullanıcıyı ve kullanıcıya ait görevleri silmek istediğinize emin misiniz?');"
                                    >

                                        🗑️ Sil

                                    </button>

                                </form>


                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

            </table>

        </div>


    </div>

</div>


<!-- ==================================================
     🌙 TEMA BUTONU
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
     🌙 TEMA JAVASCRIPT
================================================== -->

<script>

const themeToggle =
    document.getElementById("themeToggle");


// ==================================================
// DAHA ÖNCE SEÇİLMİŞ TEMAYI KONTROL ET
// ==================================================

if (
    localStorage.getItem("theme") === "dark"
) {

    document.body.classList.add("dark-mode");

    themeToggle.textContent = "☀️";

}


// ==================================================
// TEMA BUTONUNA TIKLANINCA
// ==================================================

themeToggle.addEventListener(
    "click",
    function () {

        document.body.classList.toggle(
            "dark-mode"
        );


        // ==================================================
        // KOYU MOD
        // ==================================================

        if (
            document.body.classList.contains(
                "dark-mode"
            )
        ) {

            themeToggle.textContent = "☀️";

            localStorage.setItem(
                "theme",
                "dark"
            );

        }


        // ==================================================
        // AÇIK MOD
        // ==================================================

        else {

            themeToggle.textContent = "🌙";

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