<?php

session_start();

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

if (
    !isset($_SESSION["role"])
    || $_SESSION["role"] !== "admin"
) {

    echo "Bu sayfaya erişim yetkiniz yok.";
    exit;
}


// ==================================================
// CSRF TOKEN
// ==================================================

if (empty($_SESSION["csrf_token"])) {

    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


$message = "";
$error = "";


// ==================================================
// KULLANICI ID
// ==================================================

$id = isset($_GET["id"])
    ? (int) $_GET["id"]
    : 0;


if (!$id) {

    echo "Kullanıcı bulunamadı.";
    exit;
}


// ==================================================
// KULLANICI BİLGİLERİNİ GETİR
// ==================================================

$stmt = $db->prepare("
    SELECT
        id,
        username,
        full_name,
        role
    FROM users
    WHERE id = ?
");


$stmt->execute([$id]);


$user = $stmt->fetch(PDO::FETCH_ASSOC);


if (!$user) {

    echo "Kullanıcı bulunamadı.";
    exit;
}


// ==================================================
// KULLANICI BİLGİLERİNİ GÜNCELLE
// ==================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["update_user"])
) {

    if (
        !isset($_POST["csrf_token"])
        || !hash_equals(
            $_SESSION["csrf_token"],
            $_POST["csrf_token"]
        )
    ) {

        $error =
            "Geçersiz istek. Lütfen sayfayı yenileyip tekrar deneyin.";

    } else {

        $full_name = trim(
            $_POST["full_name"] ?? ""
        );

        $username = trim(
            $_POST["username"] ?? ""
        );

        $role = $_POST["role"] ?? "user";


        if (
            empty($full_name)
            || empty($username)
        ) {

            $error =
                "Ad soyad ve kullanıcı adı boş bırakılamaz.";

        } elseif (
            !in_array(
                $role,
                ["user", "admin"],
                true
            )
        ) {

            $error = "Geçersiz rol.";

        } else {

            try {

                $stmt = $db->prepare("
                    UPDATE users
                    SET
                        full_name = ?,
                        username = ?,
                        role = ?
                    WHERE id = ?
                ");


                $stmt->execute([
                    $full_name,
                    $username,
                    $role,
                    $id
                ]);


                $message =
                    "Kullanıcı başarıyla güncellendi.";


                // Güncel bilgileri getir

                $stmt = $db->prepare("
                    SELECT
                        id,
                        username,
                        full_name,
                        role
                    FROM users
                    WHERE id = ?
                ");


                $stmt->execute([$id]);


                $user =
                    $stmt->fetch(PDO::FETCH_ASSOC);


            } catch (PDOException $e) {

                $error =
                    "Kullanıcı güncellenirken bir hata oluştu.";
            }
        }
    }
}


// ==================================================
// GÖREV SİLME
// ==================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["delete_task_id"])
) {

    if (
        !isset($_POST["csrf_token"])
        || !hash_equals(
            $_SESSION["csrf_token"],
            $_POST["csrf_token"]
        )
    ) {

        $error =
            "Geçersiz istek.";

    } else {

        $task_id =
            (int) $_POST["delete_task_id"];


        try {

            $db->beginTransaction();


            // Önce göreve ait gönderimleri sil

            $stmt = $db->prepare("
                DELETE FROM task_submissions
                WHERE task_id = ?
            ");


            $stmt->execute([
                $task_id
            ]);


            // Görevi sil

            $stmt = $db->prepare("
                DELETE FROM tasks
                WHERE id = ?
                AND assigned_to = ?
            ");


            $stmt->execute([
                $task_id,
                $id
            ]);


            $db->commit();


            $message =
                "Görev başarıyla silindi.";


        } catch (PDOException $e) {

            if ($db->inTransaction()) {

                $db->rollBack();
            }


            $error =
                "Görev silinirken bir hata oluştu.";
        }
    }
}


// ==================================================
// KULLANICIYA AİT GÖREVLER
// ==================================================

$stmt = $db->prepare("
    SELECT
        id,
        title,
        description,
        due_date,
        status,
        created_at
    FROM tasks
    WHERE assigned_to = ?
    ORDER BY id DESC
");


$stmt->execute([
    $id
]);


$tasks =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

?>


<!DOCTYPE html>

<html lang="tr">


<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Kullanıcı Düzenle - Todo App
    </title>

    <link
        rel="stylesheet"
        href="../css/style.css"
    >

</head>


<body>


<div class="standalone">


    <!-- ==================================================
         GERİ
    ================================================== -->

    <a
        class="back"
        href="kullanicilar.php"
    >

        ← Kullanıcılara Dön

    </a>


    <!-- ==================================================
         KULLANICI BİLGİLERİ
    ================================================== -->

    <div class="box">

        <h1>

            ✏️ Kullanıcı Düzenle

        </h1>


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


        <form method="POST">


            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $_SESSION["csrf_token"]
                ) ?>"
            >


            <input
                type="hidden"
                name="update_user"
                value="1"
            >


            <!-- AD SOYAD -->

            <label>

                Ad Soyad

            </label>


            <input
                type="text"
                name="full_name"
                value="<?= htmlspecialchars(
                    $user["full_name"]
                ) ?>"
                placeholder="Ad Soyad"
                required
            >


            <!-- KULLANICI ADI -->

            <label>

                Kullanıcı Adı

            </label>


            <input
                type="text"
                name="username"
                value="<?= htmlspecialchars(
                    $user["username"]
                ) ?>"
                placeholder="Kullanıcı adı"
                required
            >


            <!-- ROL -->

            <label>

                Rol

            </label>


            <select name="role">

                <option
                    value="user"
                    <?= $user["role"] === "user"
                        ? "selected"
                        : "" ?>
                >

                    Kullanıcı

                </option>


                <option
                    value="admin"
                    <?= $user["role"] === "admin"
                        ? "selected"
                        : "" ?>
                >

                    Yönetici

                </option>

            </select>


            <button
                type="submit"
                class="full-width"
            >

                💾 Değişiklikleri Kaydet

            </button>


        </form>

    </div>


    <!-- ==================================================
         KULLANICININ GÖREVLERİ
    ================================================== -->

    <div class="box">

        <h2>

            📋 <?= htmlspecialchars(
                $user["full_name"]
            ) ?> - Görevleri

        </h2>


        <p>

            Bu kullanıcıya atanmış görevler aşağıda
            listelenmektedir.

        </p>


        <?php if (empty($tasks)): ?>


            <div class="empty">

                <h3>

                    Henüz görev yok.

                </h3>

                <p>

                    Bu kullanıcıya atanmış herhangi
                    bir görev bulunmuyor.

                </p>

            </div>


        <?php else: ?>


            <?php foreach ($tasks as $task): ?>


                <div
                    class="box"
                    style="margin-top: 20px;"
                >


                    <!-- GÖREV BAŞLIĞI -->

                    <h2>

                        <?= htmlspecialchars(
                            $task["title"]
                        ) ?>

                    </h2>


                    <!-- AÇIKLAMA -->

                    <div class="task-description">

                        <strong>

                            Görev Açıklaması

                        </strong>


                        <p>

                            <?= nl2br(
                                htmlspecialchars(
                                    $task["description"]
                                )
                            ) ?>

                        </p>

                    </div>


                    <!-- TARİH -->

                    <p class="date">

                        <strong>

                            Son Tarih:

                        </strong>

                        <?= htmlspecialchars(
                            $task["due_date"] ?? "-"
                        ) ?>

                    </p>


                    <!-- DURUM -->

                    <p>

                        <strong>

                            Durum:

                        </strong>


                        <span class="status">

                            <?= htmlspecialchars(
                                $task["status"]
                            ) ?>

                        </span>

                    </p>


                    <!-- OLUŞTURULMA -->

                    <p class="date">

                        <strong>

                            Oluşturulma:

                        </strong>

                        <?= htmlspecialchars(
                            $task["created_at"]
                        ) ?>

                    </p>


                    <hr>


                    <!-- GÖREV SİL -->

                    <form
                        method="POST"
                        onsubmit="return confirm('Bu görevi ve bu göreve ait gönderilen çalışmaları silmek istediğinize emin misiniz?');"
                    >


                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(
                                $_SESSION["csrf_token"]
                            ) ?>"
                        >


                        <input
                            type="hidden"
                            name="delete_task_id"
                            value="<?= (int) $task["id"] ?>"
                        >


                        <button
                            type="submit"
                            class="delete-button"
                        >

                            🗑️ Bu Görevi Sil

                        </button>


                    </form>


                </div>


            <?php endforeach; ?>


        <?php endif; ?>


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


// Daha önce seçilmiş temayı kontrol et

if (
    localStorage.getItem("theme") === "dark"
) {

    document.body.classList.add(
        "dark-mode"
    );

    themeToggle.textContent =
        "☀️";

}


// Tema butonuna tıklanınca

themeToggle.addEventListener(
    "click",
    function () {

        document.body.classList.toggle(
            "dark-mode"
        );


        // KOYU MOD

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

        }


        // AÇIK MOD

        else {

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