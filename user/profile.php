<?php

session_start();

require_once "../config/database.php";


// ============================================================
// GİRİŞ KONTROLÜ
// ============================================================

if (!isset($_SESSION["user_id"])) {

    header("Location: ../login.php");
    exit;

}


// ============================================================
// USER KONTROLÜ
// ============================================================

if (
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "user"
) {

    echo "Bu sayfaya erişim yetkiniz yok.";
    exit;

}


// ============================================================
// KULLANICI BİLGİLERİ
// ============================================================

$user_id =
    (int) $_SESSION["user_id"];

$user_name =
    $_SESSION["full_name"] ?? "Kullanıcı";

$message = "";
$error = "";


// ============================================================
// CSRF TOKEN
// ============================================================

if (empty($_SESSION["csrf_token"])) {

    $_SESSION["csrf_token"] =
        bin2hex(random_bytes(32));

}

$csrf_token =
    $_SESSION["csrf_token"];


// ============================================================
// PROFİL BİLGİLERİ
// ============================================================

$profile_image = null;

try {

    $profile_stmt = $db->prepare("
        SELECT profile_image
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $profile_stmt->execute([
        $user_id
    ]);

    $profile_data =
        $profile_stmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($profile_data["profile_image"])) {

        $profile_image =
            $profile_data["profile_image"];

    }

} catch (PDOException $e) {

    $profile_image = null;

}


// ============================================================
// POST İŞLEMLERİ
// ============================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {


    // ========================================================
    // CSRF KONTROLÜ
    // ========================================================

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals(
            $_SESSION["csrf_token"],
            $_POST["csrf_token"]
        )
    ) {

        $error =
            "Geçersiz istek. Lütfen sayfayı yenileyip tekrar deneyin.";

    } else {


        // ====================================================
        // PROFİL FOTOĞRAFI GÜNCELLE
        // ====================================================

        if (isset($_POST["update_profile_image"])) {

            try {

                if (
                    !isset($_FILES["profile_image"]) ||
                    $_FILES["profile_image"]["error"] === UPLOAD_ERR_NO_FILE
                ) {

                    throw new Exception(
                        "Lütfen bir profil fotoğrafı seçin."
                    );

                }


                if (
                    $_FILES["profile_image"]["error"] !== UPLOAD_ERR_OK
                ) {

                    throw new Exception(
                        "Profil fotoğrafı yüklenirken bir hata oluştu."
                    );

                }


                // Maksimum 5 MB
                $max_profile_size =
                    5 * 1024 * 1024;


                if (
                    $_FILES["profile_image"]["size"]
                    > $max_profile_size
                ) {

                    throw new Exception(
                        "Profil fotoğrafı en fazla 5 MB olabilir."
                    );

                }


                // Sadece JPG / JPEG / PNG
                $allowed_profile_extensions = [
                    "jpg",
                    "jpeg",
                    "png"
                ];


                $original_name =
                    $_FILES["profile_image"]["name"];

                $extension =
                    strtolower(
                        pathinfo(
                            $original_name,
                            PATHINFO_EXTENSION
                        )
                    );


                if (
                    !in_array(
                        $extension,
                        $allowed_profile_extensions,
                        true
                    )
                ) {

                    throw new Exception(
                        "Profil fotoğrafı sadece JPG, JPEG veya PNG olabilir."
                    );

                }


                // ====================================================
                // MIME KONTROLÜ
                // ====================================================

                $finfo =
                    finfo_open(FILEINFO_MIME_TYPE);

                if ($finfo === false) {

                    throw new Exception(
                        "Dosya türü kontrol edilemedi."
                    );

                }


                $mime_type =
                    finfo_file(
                        $finfo,
                        $_FILES["profile_image"]["tmp_name"]
                    );


                finfo_close($finfo);


                $allowed_profile_mimes = [

                    "jpg"  => "image/jpeg",
                    "jpeg" => "image/jpeg",
                    "png"  => "image/png"

                ];


                if (
                    !isset(
                        $allowed_profile_mimes[$extension]
                    ) ||
                    $mime_type !==
                    $allowed_profile_mimes[$extension]
                ) {

                    throw new Exception(
                        "Geçersiz profil fotoğrafı."
                    );

                }


                // ====================================================
                // PROFİL FOTOĞRAFLARI KLASÖRÜ
                // ====================================================

                $profile_upload_dir =
                    "../uploads/profile_images/";


                if (!is_dir($profile_upload_dir)) {

                    if (
                        !mkdir(
                            $profile_upload_dir,
                            0775,
                            true
                        )
                    ) {

                        throw new Exception(
                            "Profil fotoğrafı klasörü oluşturulamadı."
                        );

                    }

                }


                // ====================================================
                // ESKİ FOTOĞRAFI AL
                // ====================================================

                $old_profile_stmt =
                    $db->prepare("
                        SELECT profile_image
                        FROM users
                        WHERE id = ?
                        LIMIT 1
                    ");


                $old_profile_stmt->execute([
                    $user_id
                ]);


                $old_profile_image =
                    $old_profile_stmt->fetchColumn();


                // ====================================================
                // YENİ DOSYA ADI
                // ====================================================

                $safe_profile_name =
                    "profile_"
                    . $user_id
                    . "_"
                    . bin2hex(
                        random_bytes(8)
                    )
                    . "."
                    . $extension;


                $profile_target =
                    $profile_upload_dir
                    . $safe_profile_name;


                if (
                    !move_uploaded_file(
                        $_FILES["profile_image"]["tmp_name"],
                        $profile_target
                    )
                ) {

                    throw new Exception(
                        "Profil fotoğrafı sunucuya yüklenemedi."
                    );

                }


                // ====================================================
                // SQLITE'A KAYDET
                // ====================================================

                $profile_path =
                    "uploads/profile_images/"
                    . $safe_profile_name;


                $profile_update_stmt =
                    $db->prepare("
                        UPDATE users
                        SET profile_image = ?
                        WHERE id = ?
                    ");


                $profile_update_stmt->execute([

                    $profile_path,
                    $user_id

                ]);


                // ====================================================
                // ESKİ FOTOĞRAFI SİL
                // ====================================================

                if (!empty($old_profile_image)) {

                    $old_profile_file =
                        dirname(__DIR__)
                        . DIRECTORY_SEPARATOR
                        . str_replace(
                            "/",
                            DIRECTORY_SEPARATOR,
                            $old_profile_image
                        );


                    if (
                        is_file($old_profile_file)
                    ) {

                        @unlink(
                            $old_profile_file
                        );

                    }

                }


                $profile_image =
                    $profile_path;


                $message =
                    "Profil fotoğrafınız başarıyla güncellendi.";


            } catch (Exception $e) {

                $error =
                    $e->getMessage();

            } catch (PDOException $e) {

                $error =
                    "Profil fotoğrafı güncellenirken bir hata oluştu.";

            }

        }


        // ====================================================
        // PROFİL FOTOĞRAFI SİL
        // ====================================================

        if (isset($_POST["delete_profile_image"])) {

            try {

                // Mevcut fotoğrafı al
                $delete_stmt =
                    $db->prepare("
                        SELECT profile_image
                        FROM users
                        WHERE id = ?
                        LIMIT 1
                    ");

                $delete_stmt->execute([
                    $user_id
                ]);

                $delete_profile_image =
                    $delete_stmt->fetchColumn();


                // Veritabanından kaldır
                $delete_update_stmt =
                    $db->prepare("
                        UPDATE users
                        SET profile_image = NULL
                        WHERE id = ?
                    ");

                $delete_update_stmt->execute([
                    $user_id
                ]);


                // Dosyayı fiziksel olarak sil
                if (!empty($delete_profile_image)) {

                    $delete_file =
                        dirname(__DIR__)
                        . DIRECTORY_SEPARATOR
                        . str_replace(
                            "/",
                            DIRECTORY_SEPARATOR,
                            $delete_profile_image
                        );


                    if (is_file($delete_file)) {

                        @unlink($delete_file);

                    }

                }


                $profile_image = null;


                $message =
                    "Profil fotoğrafınız başarıyla silindi.";


            } catch (PDOException $e) {

                $error =
                    "Profil fotoğrafı silinirken bir hata oluştu.";

            }

        }

    }

}

?>

<!DOCTYPE html>
<html lang="tr">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Profil - TODO APP</title>
<link
    rel="stylesheet"
    href="../assets/css/style.css"


<body>

    <div class="profile-page">

        <h1>Profil</h1>


        <?php if (!empty($message)): ?>

            <div class="profile-message">
                <?= htmlspecialchars(
                    $message,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </div>

        <?php endif; ?>


        <?php if (!empty($error)): ?>

            <div class="profile-error">
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </div>

        <?php endif; ?>


        <!-- ====================================================
             PROFİL BİLGİLERİ
        ==================================================== -->

        <div class="profile-info">


            <!-- PROFİL FOTOĞRAFI -->

            <div class="profile-image">

                <?php if (!empty($profile_image)): ?>

                    <img
                        src="../<?= htmlspecialchars(
                            $profile_image,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                        alt="Profil Fotoğrafı"
                    >

                <?php else: ?>

                    <div class="profile-placeholder">
                        👤
                    </div>

                <?php endif; ?>

            </div>


            <!-- KULLANICI ADI -->

            <h2>
                <?= htmlspecialchars(
                    $user_name,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </h2>


            <!-- ==================================================
                 FOTOĞRAF İŞLEMLERİ
            ================================================== -->

            <div class="profile-actions">


                <!-- FOTOĞRAF DEĞİŞTİR -->

                <form
                    method="POST"
                    enctype="multipart/form-data"
                    class="profile-upload-form"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(
                            $csrf_token,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                    >

                    <input
                        type="hidden"
                        name="update_profile_image"
                        value="1"
                    >

                    <label
                        for="profileImageInput"
                        class="profile-upload-button"
                    >
                        📷 Fotoğraf Değiştir
                    </label>

                    <input
                        type="file"
                        id="profileImageInput"
                        name="profile_image"
                        accept=".jpg,.jpeg,.png"
                        hidden
                    >

                    <button
                        type="submit"
                        class="profile-upload-submit"
                    >
                        Fotoğrafı Yükle
                    </button>

                </form>


                <!-- FOTOĞRAF SİL -->

                <?php if (!empty($profile_image)): ?>

                    <form
                        method="POST"
                        class="profile-delete-form"
                        onsubmit="return confirm('Profil fotoğrafını silmek istediğinize emin misiniz?');"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(
                                $csrf_token,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>"
                        >

                        <input
                            type="hidden"
                            name="delete_profile_image"
                            value="1"
                        >

                        <button
                            type="submit"
                            class="profile-delete-button"
                        >
                            🗑️ Fotoğrafı Sil
                        </button>

                    </form>

                <?php endif; ?>


            </div>


        </div>


        <!-- DASHBOARD'A DÖN -->

        <a
            href="dashboard.php"
            class="profile-back"
        >
            ← Dashboard'a Dön
        </a>


    </div>


    <!-- ========================================================
         FOTOĞRAF SEÇİLİNCE "FOTOĞRAFI YÜKLE" GÖSTER
    ======================================================== -->

    <script>

        const profileImageInput =
            document.getElementById("profileImageInput");

        const profileUploadSubmit =
            document.querySelector(".profile-upload-submit");


        if (profileImageInput && profileUploadSubmit) {

            profileUploadSubmit.style.display = "none";


            profileImageInput.addEventListener(
                "change",
                function () {

                    if (
                        this.files &&
                        this.files.length > 0
                    ) {

                        profileUploadSubmit.style.display =
                            "inline-flex";

                    }

                }
            );

        }

    </script>

</body>

</html>