<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    require_once __DIR__ . "/../config/session.php";
}

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
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    echo "Bu sayfaya erişim yetkiniz yok.";
    exit;
}


$admin_id = (int) $_SESSION["user_id"];

function resolveProfileUploadFile(string $storedPath): string|false
{
    $normalized = str_replace("\\", "/", trim($storedPath));

    while (strpos($normalized, "../") === 0) {
        $normalized = substr($normalized, 3);
    }

    $normalized = ltrim($normalized, "/");

    if (
        $normalized === ""
        || strpos($normalized, "..") !== false
        || strpos($normalized, "uploads/profile_images/") !== 0
    ) {
        return false;
    }

    $root = realpath(__DIR__ . "/../uploads/profile_images");
    $candidate = realpath(__DIR__ . "/../" . $normalized);

    if (
        $root === false
        || $candidate === false
        || !is_file($candidate)
        || strpos($candidate, $root . DIRECTORY_SEPARATOR) !== 0
    ) {
        return false;
    }

    return $candidate;
}

function isValidTaskDueDate(string $value): bool
{
    if ($value === "") {
        return false;
    }

    $date = DateTime::createFromFormat("!Y-m-d", $value);

    return $date instanceof DateTime
        && $date->format("Y-m-d") === $value;
}

// ==================================================
// ADMIN PROFİL FOTOĞRAFI
// ==================================================

$profile_image = null;

try {

    $profile_stmt = $db->prepare("
        SELECT profile_image
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $profile_stmt->execute([
        $admin_id
    ]);

    $profile_data = $profile_stmt->fetch(PDO::FETCH_ASSOC);

    if (!empty($profile_data["profile_image"])) {

        $profile_image = $profile_data["profile_image"];

    }

} catch (PDOException $e) {

    $profile_image = null;

}

$message = "";
$error = "";

// ==================================================
// ADMIN PROFİL BİLGİSİ
// ==================================================

$profile_image = null;

try {

    $profile_stmt = $db->prepare("
        SELECT profile_image
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $profile_stmt->execute([
        $admin_id
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

// ==================================================
// CSRF TOKEN
// ==================================================

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

$csrf_token = $_SESSION["csrf_token"];

$active_section = "dashboard";


// ==================================================
// POST İŞLEMLERİ
// ==================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // ==================================================
    // CSRF KONTROLÜ
    // ==================================================

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

        // ==================================================
        // TEKİL BİLDİRİMİ OKUNDU / OKUNMADI YAP
        // ==================================================

        if (isset($_POST["notification_action"])) {
            $notification_action = (string) $_POST["notification_action"];
            $notification_id = filter_var(
                $_POST["notification_id"] ?? null,
                FILTER_VALIDATE_INT,
                ["options" => ["min_range" => 1]]
            );
            $active_section = "bildirimler";

            if (
                $notification_id === false ||
                !in_array($notification_action, ["mark_read", "mark_unread"], true)
            ) {
                $error = "Geçersiz bildirim işlemi.";
            } else {
                try {
                    $new_read_value = $notification_action === "mark_read" ? 1 : 0;
                    $notification_update = $db->prepare(
                        "UPDATE notifications
                         SET is_read = ?
                         WHERE id = ?
                           AND user_id = ?"
                    );
                    $notification_update->execute([
                        $new_read_value,
                        $notification_id,
                        $admin_id
                    ]);

                    if ($notification_update->rowCount() > 0) {
                        $message = $new_read_value === 1
                            ? "Bildirim okundu olarak işaretlendi."
                            : "Bildirim okunmadı olarak işaretlendi.";
                    } else {
                        $error = "Bildirim bulunamadı veya bu işlem için yetkiniz yok.";
                    }
                } catch (PDOException $e) {
                    $error = "Bildirim güncellenirken hata oluştu.";
                }
            }
        }

        // ==================================================
        // ADMIN PROFİL FOTOĞRAFI GÜNCELLE
        // ==================================================

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
            || !is_uploaded_file(
                (string) ($_FILES["profile_image"]["tmp_name"] ?? "")
            )
        ) {

            throw new Exception(
                "Profil fotoğrafı yüklenirken bir hata oluştu."
            );

        }

        // Maksimum 5 MB
        $max_profile_size = 5 * 1024 * 1024;

        if (
            $_FILES["profile_image"]["size"] > $max_profile_size
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

        // MIME kontrolü
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

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
            !isset($allowed_profile_mimes[$extension]) ||
            $mime_type !== $allowed_profile_mimes[$extension]
        ) {

            throw new Exception(
                "Geçersiz profil fotoğrafı."
            );

        }

        // Profil klasörü
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

        // Eski fotoğrafı al
        $old_profile_stmt =
            $db->prepare("
                SELECT profile_image
                FROM users
                WHERE id = ?
                LIMIT 1
            ");

        $old_profile_stmt->execute([
            $admin_id
        ]);

        $old_profile_image =
            $old_profile_stmt->fetchColumn();

        // Yeni dosya adı
        $safe_profile_name =
            "profile_"
            . $admin_id
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

        // Veritabanına kaydet
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
            $admin_id
        ]);

        // Eski fotoğrafı yalnızca izin verilen profil klasörü içindeyse sil.
        if (!empty($old_profile_image)) {

            $old_profile_file = resolveProfileUploadFile(
                (string) $old_profile_image
            );

            if ($old_profile_file !== false) {
                @unlink($old_profile_file);
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
// ==================================================
// ADMIN PROFİL FOTOĞRAFI SİL
// ==================================================

if (isset($_POST["delete_profile_image"])) {
try {

    // Mevcut fotoğrafı veritabanından al
    $delete_stmt = $db->prepare("
        SELECT profile_image
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $delete_stmt->execute([
        $admin_id
    ]);

    $delete_profile_image =
        $delete_stmt->fetchColumn();


    // Veritabanından fotoğraf yolunu kaldır
    $delete_update_stmt = $db->prepare("
        UPDATE users
        SET profile_image = NULL
        WHERE id = ?
    ");

    $delete_update_stmt->execute([
        $admin_id
    ]);


    // Fiziksel dosyayı yalnızca izin verilen profil klasörü içindeyse sil.
    if (!empty($delete_profile_image)) {

        $delete_file = resolveProfileUploadFile(
            (string) $delete_profile_image
        );

        if ($delete_file !== false) {
            @unlink($delete_file);
        }
    }


    // Ekrandaki profil fotoğrafını kaldır
    $profile_image = null;

    $message =
        "Profil fotoğrafınız başarıyla silindi.";


} catch (PDOException $e) {

    $error =
        "Profil fotoğrafı silinirken bir hata oluştu.";

}


}



        // ==================================================
        // 1. KULLANICI EKLE
        // ==================================================

        if (isset($_POST["add_user"])) {

            $full_name =
                trim($_POST["full_name"] ?? "");

            $username =
                trim($_POST["username"] ?? "");

            $password =
                $_POST["password"] ?? "";


            if (
                $full_name === "" ||
                $username === "" ||
                $password === ""
            ) {

                $error =
                    "Lütfen tüm kullanıcı alanlarını doldurun.";

            } elseif (
                mb_strlen($full_name) > 120
                || mb_strlen($username) > 80
                || !preg_match('/^[A-Za-z0-9_.-]+$/', $username)
            ) {

                $error =
                    "Ad, kullanıcı adı veya kullanıcı adı biçimi geçersiz.";

            } elseif (strlen($password) < 8) {

                $error =
                    "Şifre en az 6 karakter olmalıdır.";

            } else {

                try {

                    $check_stmt = $db->prepare("
                        SELECT id
                        FROM users
                        WHERE username = ?
                    ");

                    $check_stmt->execute([
                        $username
                    ]);


                    if ($check_stmt->fetch()) {

                        $error =
                            "Bu kullanıcı adı zaten kullanılıyor.";

                    } else {

                        $hashed_password =
                            password_hash(
                                $password,
                                PASSWORD_DEFAULT
                            );


                        $stmt = $db->prepare("
                            INSERT INTO users
                            (
                                full_name,
                                username,
                                password,
                                role
                            )
                            VALUES (?, ?, ?, 'user')
                        ");

                        $stmt->execute([
                            $full_name,
                            $username,
                            $hashed_password
                        ]);


                        $message =
                            "Kullanıcı başarıyla oluşturuldu.";
                    }


                } catch (PDOException $e) {

                    $error =
                        "Kullanıcı oluşturulurken bir hata oluştu.";
                }
            }
        }



        // ==================================================
        // 2. KULLANICI DÜZENLE
        // ==================================================

        if (isset($_POST["edit_user"])) {

            $user_id =
                (int) ($_POST["user_id"] ?? 0);

            $full_name =
                trim($_POST["edit_full_name"] ?? "");

            $username =
                trim($_POST["edit_username"] ?? "");

            $new_password =
                $_POST["edit_password"] ?? "";

            $role =
                $_POST["edit_role"] ?? "user";


            if (
                empty($user_id) ||
                empty($full_name) ||
                empty($username)
            ) {

                $error =
                    "Kullanıcı bilgilerini eksiksiz doldurun.";

            } elseif (
                !in_array(
                    $role,
                    ["user", "admin"],
                    true
                )
                || mb_strlen($full_name) > 120
                || mb_strlen($username) > 80
                || !preg_match('/^[A-Za-z0-9_.-]+$/', $username)
            ) {

                $error =
                    "Geçersiz kullanıcı rolü.";

            } elseif (
                $user_id === $admin_id &&
                $role !== "admin"
            ) {

                $error =
                    "Kendi admin yetkinizi kaldıramazsınız.";

            } else {

                try {

                    // Kullanıcı var mı?
                    $user_check = $db->prepare("
                        SELECT id, role
                        FROM users
                        WHERE id = ?
                    ");

                    $user_check->execute([
                        $user_id
                    ]);

                    $existing_user =
                        $user_check->fetch(
                            PDO::FETCH_ASSOC
                        );


                    if (!$existing_user) {

                        $error =
                            "Düzenlenecek kullanıcı bulunamadı.";

                    } else {

                        // Kullanıcı adı başka kullanıcıda mı?
                        $username_check = $db->prepare("
                            SELECT id
                            FROM users
                            WHERE username = ?
                            AND id != ?
                        ");

                        $username_check->execute([
                            $username,
                            $user_id
                        ]);


                        if ($username_check->fetch()) {

                            $error =
                                "Bu kullanıcı adı başka bir kullanıcı tarafından kullanılıyor.";

                        } else {

                            // Şifre değiştirilmişse
                            if (!empty($new_password)) {

                                if (
                                    strlen($new_password) < 8
                                ) {

                                    $error =
                                        "Yeni şifre en az 8 karakter olmalıdır.";

                                } else {

                                    $hashed_password =
                                        password_hash(
                                            $new_password,
                                            PASSWORD_DEFAULT
                                        );

                                    $stmt = $db->prepare("
                                        UPDATE users
                                        SET
                                            full_name = ?,
                                            username = ?,
                                            password = ?,
                                            role = ?
                                        WHERE id = ?
                                    ");

                                    $stmt->execute([
                                        $full_name,
                                        $username,
                                        $hashed_password,
                                        $role,
                                        $user_id
                                    ]);

                                    $message =
                                        "Kullanıcı bilgileri başarıyla güncellendi.";
                                }

                            } else {

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
                                    $user_id
                                ]);

                                $message =
                                    "Kullanıcı bilgileri başarıyla güncellendi.";
                            }
                        }
                    }

                } catch (PDOException $e) {

                    $error =
                        "Kullanıcı güncellenirken bir hata oluştu.";
                }
            }
        }



        // ==================================================
        // 3. KULLANICI SİL
        // ==================================================

        if (isset($_POST["delete_user"])) {

            $user_id =
                (int) ($_POST["user_id"] ?? 0);


            if (empty($user_id)) {

                $error =
                    "Geçersiz kullanıcı.";

            } elseif ($user_id === $admin_id) {

                $error =
                    "Kendi hesabınızı silemezsiniz.";

            } else {

                try {

                    $user_stmt = $db->prepare("
                        SELECT
                            id,
                            full_name,
                            role
                        FROM users
                        WHERE id = ?
                    ");

                    $user_stmt->execute([
                        $user_id
                    ]);

                    $delete_user =
                        $user_stmt->fetch(
                            PDO::FETCH_ASSOC
                        );


                    if (!$delete_user) {

                        $error =
                            "Silinecek kullanıcı bulunamadı.";

                    } elseif (($delete_user["role"] ?? "") !== "user") {

                        $error =
                            "Admin hesabı bu ekrandan silinemez.";

                    } else {

                        $db->beginTransaction();

                        /*
                         * Kullanıcıya ait görevler varsa
                         * önce görevleri siliyoruz.
                         */

                        $task_stmt = $db->prepare("
                            SELECT id
                            FROM tasks
                            WHERE assigned_to = ?
                            OR assigned_by = ?
                        ");

                        $task_stmt->execute([
                            $user_id,
                            $user_id
                        ]);

                        $user_tasks =
                            $task_stmt->fetchAll(
                                PDO::FETCH_COLUMN
                            );


                        /*
                         * Görevlere ait çalışmalar
                         */

                        if (!empty($user_tasks)) {

                            $delete_submission =
                                $db->prepare("
                                    DELETE FROM task_submissions
                                    WHERE task_id = ?
                                    OR user_id = ?
                                ");

                            foreach (
                                $user_tasks as $task_id
                            ) {

                                $delete_submission->execute([
                                    $task_id,
                                    $user_id
                                ]);
                            }
                        }


                        /*
                         * Kullanıcının kendi görev çalışmaları
                         */

                        $delete_user_submissions =
                            $db->prepare("
                                DELETE FROM task_submissions
                                WHERE user_id = ?
                            ");

                        $delete_user_submissions->execute([
                            $user_id
                        ]);


                        /*
                         * Kullanıcı aktiviteleri
                         */

                        $delete_activities =
                            $db->prepare("
                                DELETE FROM user_activities
                                WHERE user_id = ?
                            ");

                        $delete_activities->execute([
                            $user_id
                        ]);


                        /*
                         * Bildirimler
                         */

                        $delete_notifications =
                            $db->prepare("
                                DELETE FROM notifications
                                WHERE user_id = ?
                            ");

                        $delete_notifications->execute([
                            $user_id
                        ]);


                        /*
                         * Kullanıcının görevleri
                         */

                        $delete_tasks =
                            $db->prepare("
                                DELETE FROM tasks
                                WHERE assigned_to = ?
                                OR assigned_by = ?
                            ");

                        $delete_tasks->execute([
                            $user_id,
                            $user_id
                        ]);


                        /*
                         * Son olarak kullanıcı
                         */

                        $delete_user_stmt =
                            $db->prepare("
                                DELETE FROM users
                                WHERE id = ?
                            ");

                        $delete_user_stmt->execute([
                            $user_id
                        ]);


                        $db->commit();


                        $message =
                            "Kullanıcı ve kullanıcıya bağlı kayıtlar başarıyla silindi.";
                    }

                } catch (PDOException $e) {

                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }

                    $error =
                        "Kullanıcı silinirken bir hata oluştu.";
                }
            }
        }



        // ==================================================
        // 4. YENİ GÖREV OLUŞTUR
        // ==================================================

        if (isset($_POST["create_task"])) {

            $title =
                trim($_POST["title"] ?? "");

            $description =
                trim($_POST["description"] ?? "");

            $assigned_to = filter_var(
                $_POST["assigned_to"] ?? null,
                FILTER_VALIDATE_INT,
                ["options" => ["min_range" => 1]]
            );

            $due_date = trim(
                (string) ($_POST["due_date"] ?? "")
            );

            $priority = $_POST["priority"] ?? "normal";
            $valid_priorities = ["low", "normal", "high", "urgent"];


            if (
                $title === "" ||
                $description === "" ||
                $assigned_to === false ||
                !isValidTaskDueDate($due_date) ||
                !in_array($priority, $valid_priorities, true)
            ) {

                $error =
                    "Lütfen tüm görev alanlarını doldurun.";

            } else {

                try {

                    $user_stmt = $db->prepare("
                        SELECT id, full_name
                        FROM users
                        WHERE id = ?
                              AND role = 'user'
                    ");

                    $user_stmt->execute([
                        $assigned_to
                    ]);

                    $assigned_user =
                        $user_stmt->fetch(
                            PDO::FETCH_ASSOC
                        );


                    if (!$assigned_user) {

                        $error =
                            "Seçilen kullanıcı bulunamadı.";

                    } else {

                        $stmt = $db->prepare("
                            INSERT INTO tasks
                            (
                                title,
                                description,
                                assigned_to,
                                assigned_by,
                                due_date,
                                status,
                                priority,
                                created_at
                            )
                            VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                        ");

                        $stmt->execute([
                            $title,
                            $description,
                            $assigned_to,
                            $admin_id,
                            $due_date,
                            "bekliyor",
                            $priority
                        ]);


                        $notification_stmt = $db->prepare("
                            INSERT INTO notifications
                            (
                                user_id,
                                title,
                                message,
                                is_read,
                                created_at
                            )
                            VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                        ");

                        $notification_stmt->execute([
                            $assigned_to,
                            "📋 Yeni Görev Atandı",
                            "Size yeni bir görev atandı: " . $title,
                            0
                        ]);


                        $message =
                            "Görev başarıyla oluşturuldu ve kullanıcıya atandı.";
                    }

                } catch (PDOException $e) {

                    $error =
                        "Görev oluşturulurken bir hata oluştu.";
                }
            }
        }



        // ==================================================
        // 5. GÖREV DÜZENLE
        // ==================================================

        if (isset($_POST["edit_task"])) {

            $task_id =
                (int) ($_POST["task_id"] ?? 0);

            $title =
                trim($_POST["edit_title"] ?? "");

            $description =
                trim($_POST["edit_description"] ?? "");

            $assigned_to = filter_var(
                $_POST["edit_assigned_to"] ?? null,
                FILTER_VALIDATE_INT,
                ["options" => ["min_range" => 1]]
            );

            $due_date = trim(
                (string) ($_POST["edit_due_date"] ?? "")
            );

            $priority = $_POST["edit_priority"] ?? "normal";
            $valid_priorities = ["low", "normal", "high", "urgent"];


            if (
                $task_id < 1 ||
                $title === "" ||
                $description === "" ||
                $assigned_to === false ||
                mb_strlen($title) > 200 ||
                mb_strlen($description) > 5000 ||
                !isValidTaskDueDate($due_date) ||
                !in_array($priority, $valid_priorities, true)
            ) {

                $error =
                    "Görev bilgilerini eksiksiz doldurun.";

            } else {

                try {

                    $task_stmt = $db->prepare("
                        SELECT
                            id,
                            assigned_to,
                            title
                        FROM tasks
                        WHERE id = ?
                        AND assigned_by = ?
                        AND deleted_at IS NULL
                    ");

                    $task_stmt->execute([
                        $task_id,
                        $admin_id
                    ]);

                    $old_task =
                        $task_stmt->fetch(
                            PDO::FETCH_ASSOC
                        );


                    if (!$old_task) {

                        $error =
                            "Bu görevi düzenleme yetkiniz yok.";

                    } else {

                        $user_stmt = $db->prepare("
                            SELECT id, full_name
                            FROM users
                            WHERE id = ?
                              AND role = 'user'
                        ");

                        $user_stmt->execute([
                            $assigned_to
                        ]);

                        $new_user =
                            $user_stmt->fetch(
                                PDO::FETCH_ASSOC
                            );


                        if (!$new_user) {

                            $error =
                                "Seçilen kullanıcı bulunamadı.";

                        } else {

                            $stmt = $db->prepare("
                                UPDATE tasks
                                SET
                                    title = ?,
                                    description = ?,
                                    assigned_to = ?,
                                    due_date = ?,
                                    priority = ?
                                WHERE id = ?
                                AND assigned_by = ?
                            ");

                            $stmt->execute([
                                $title,
                                $description,
                                $assigned_to,
                                $due_date,
                                $priority,
                                $task_id,
                                $admin_id
                            ]);


                            $notification_stmt =
                                $db->prepare("
                                    INSERT INTO notifications
                                    (
                                        user_id,
                                        title,
                                        message,
                                        is_read,
                                        created_at
                                    )
                                    VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                                ");


                            if (
                                (int) $old_task["assigned_to"] !==
                                $assigned_to
                            ) {

                                $notification_stmt->execute([
                                    $assigned_to,
                                    "📋 Görev Güncellendi",
                                    "Size yeni bir görev atandı: " . $title,
                                    0
                                ]);

                            } else {

                                $notification_stmt->execute([
                                    $assigned_to,
                                    "✏️ Görev Güncellendi",
                                    "Göreviniz güncellendi: " . $title,
                                    0
                                ]);
                            }


                            $message =
                                "Görev başarıyla güncellendi.";
                        }
                    }

                } catch (PDOException $e) {

                    $error =
                        "Görev güncellenirken bir hata oluştu.";
                }
            }
        }



        // ==================================================
        // 6. GÖREV SİL
        // ==================================================

        if (isset($_POST["delete_task"])) {

            $task_id =
                (int) ($_POST["task_id"] ?? 0);


            if (empty($task_id)) {

                $error =
                    "Geçersiz görev.";

            } else {

                try {

                    $task_stmt = $db->prepare("
                        SELECT
                            id,
                            assigned_to,
                            title
                        FROM tasks
                        WHERE id = ?
                        AND assigned_by = ?
                        AND deleted_at IS NULL
                    ");

                    $task_stmt->execute([
                        $task_id,
                        $admin_id
                    ]);

                    $task =
                        $task_stmt->fetch(
                            PDO::FETCH_ASSOC
                        );


                    if (!$task) {

                        $error =
                            "Bu görevi silme yetkiniz yok.";

                    } else {

                        /*
                         * Önce göreve ait çalışmalar
                         */



                        /*
                         * Bildirim
                         */

                        $notification_stmt =
                            $db->prepare("
                                INSERT INTO notifications
                                (
                                    user_id,
                                    title,
                                    message,
                                    is_read,
                                    created_at
                                )
                                VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                            ");

                        $notification_stmt->execute([
                            $task["assigned_to"],
                            "🗑️ Görev Silindi",
                            "Göreviniz silindi: " . $task["title"],
                            0
                        ]);


                        /*
                         * Görevi sil
                         */

                        $delete_task =
                            $db->prepare("
                                UPDATE tasks
                                SET deleted_at = CURRENT_TIMESTAMP,
                                    deleted_by = ?
                                WHERE id = ?
                                AND assigned_by = ?
                                AND deleted_at IS NULL
                            ");

                        $delete_task->execute([
                            $admin_id,
                            $task_id,
                            $admin_id
                        ]);


                        $message =
                            "Görev başarıyla silindi.";
                    }

                } catch (PDOException $e) {

                    $error =
                        "Görev silinirken bir hata oluştu.";
                }
            }
        }



        // ==================================================
        // 7. GÖREVİ ONAYLA
        // ==================================================

        if (isset($_POST["approve_task"])) {

            $task_id =
                (int) ($_POST["task_id"] ?? 0);


            try {

                $check_stmt = $db->prepare("
                    SELECT
                        id,
                        assigned_to,
                        title,
                        due_date,
                        status
                    FROM tasks
                    WHERE id = ?
                    AND assigned_by = ?
                    AND deleted_at IS NULL
                ");

                $check_stmt->execute([
                    $task_id,
                    $admin_id
                ]);

                $task_check =
                    $check_stmt->fetch(
                        PDO::FETCH_ASSOC
                    );


                if (!$task_check) {

                    $error =
                        "Görev bulunamadı veya bu görevi onaylama yetkiniz yok.";

                } elseif (
                    $task_check["status"] !== "incelemede"
                ) {

                    $error =
                        "Yalnızca incelemede durumundaki görevler onaylanabilir.";

                } elseif (
                    !empty($task_check["due_date"]) &&
                    $task_check["due_date"] < date("Y-m-d")
                ) {

                    $error =
                        "Bu görevin süresi dolduğu için onaylanamaz.";

                } else {

                    $stmt = $db->prepare("
                        UPDATE tasks
                        SET status = 'onaylandı'
                        WHERE id = ?
                        AND assigned_by = ?
                        AND deleted_at IS NULL
                        AND status = 'incelemede'
                    ");

                    $stmt->execute([
                        $task_id,
                        $admin_id
                    ]);


                    $notification_stmt = $db->prepare("
                        INSERT INTO notifications
                        (
                            user_id,
                            title,
                            message,
                            is_read,
                            created_at
                        )
                        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ");

                    $notification_stmt->execute([
                        $task_check["assigned_to"],
                        "✅ Görev Onaylandı",
                        "Göreviniz onaylandı: " .
                        $task_check["title"],
                        0
                    ]);


                    $message =
                        "Görev başarıyla onaylandı.";
                }

            } catch (PDOException $e) {

                $error =
                    "Görev onaylanırken bir hata oluştu.";
            }
        }



        // ==================================================
        // 8. REVİZYON İSTE
        // ==================================================

        if (isset($_POST["revision_task"])) {

            $task_id =
                (int) ($_POST["task_id"] ?? 0);


            try {

                $check_stmt = $db->prepare("
                    SELECT
                        id,
                        assigned_to,
                        title,
                        due_date,
                        status
                    FROM tasks
                    WHERE id = ?
                    AND assigned_by = ?
                    AND deleted_at IS NULL
                ");

                $check_stmt->execute([
                    $task_id,
                    $admin_id
                ]);

                $task_check =
                    $check_stmt->fetch(
                        PDO::FETCH_ASSOC
                    );


                if (!$task_check) {

                    $error =
                        "Görev bulunamadı veya bu işlem için yetkiniz yok.";

                } elseif (
                    $task_check["status"] !== "incelemede"
                ) {

                    $error =
                        "Yalnızca incelemede durumundaki görevler için revizyon istenebilir.";

                } elseif (
                    !empty($task_check["due_date"]) &&
                    $task_check["due_date"] < date("Y-m-d")
                ) {

                    $error =
                        "Bu görevin süresi dolduğu için revizyon işlemi yapılamaz.";

                } else {

                    $stmt = $db->prepare("
                        UPDATE tasks
                        SET status = 'revizyon'
                        WHERE id = ?
                        AND assigned_by = ?
                        AND deleted_at IS NULL
                        AND status = 'incelemede'
                    ");

                    $stmt->execute([
                        $task_id,
                        $admin_id
                    ]);


                    $notification_stmt = $db->prepare("
                        INSERT INTO notifications
                        (
                            user_id,
                            title,
                            message,
                            is_read,
                            created_at
                        )
                        VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                    ");

                    $notification_stmt->execute([
                        $task_check["assigned_to"],
                        "⚠️ Revizyon İstendi",
                        "Göreviniz için revizyon istendi: " .
                        $task_check["title"],
                        0
                    ]);


                    $message =
                        "Görev revizyona gönderildi.";
                }

            } catch (PDOException $e) {

                $error =
                    "Revizyon işlemi sırasında bir hata oluştu.";
            }
        }
    }
}



// ==================================================
// TÜM KULLANICILARI GETİR
// ADMINLER DE DAHİL
// ==================================================

$stmt = $db->query("
    SELECT
        id,
        full_name,
        username,
        role
    FROM users
    ORDER BY
        CASE
            WHEN role = 'admin' THEN 0
            ELSE 1
        END,
        full_name ASC
");

$all_users =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


// ==================================================
// TÜM KULLANICILARI GETİR
// GÖREV ATAMA VEYA FİLTRELEME İÇİN (ADMİNLER DAHİL)
// ==================================================

$stmt = $db->query("
    SELECT
        id,
        full_name,
        username,
        role
    FROM users
    ORDER BY full_name ASC
");

$users =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


// ==================================================
// ADMİNİN GÖREVLERİNİ GETİR
// ==================================================

$stmt = $db->prepare("
    SELECT
        t.id,
        t.title,
        t.description,
        t.assigned_to,
        t.due_date,
        t.status,
        t.priority,
        t.created_at,
        u.full_name AS user_name,
        u.username AS username,
        assigned_by_user.full_name AS assigned_by_name,
        assigned_by_user.username AS assigned_by_username
    FROM tasks t

    INNER JOIN users u
        ON t.assigned_to = u.id

    LEFT JOIN users assigned_by_user
        ON t.assigned_by = assigned_by_user.id

    WHERE t.assigned_by = ?
      AND t.deleted_at IS NULL

    ORDER BY t.id DESC
");

$stmt->execute([
    $admin_id
]);

$tasks =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


// ==================================================
// SÜRESİ GEÇEN GÖREVLER
// ==================================================

$today = date("Y-m-d");

foreach ($tasks as &$task) {

    $task["is_expired"] = false;

    if (
        !empty($task["due_date"]) &&
        $task["due_date"] < $today &&
        $task["status"] !== "onaylandı"
    ) {

        $task["is_expired"] = true;
    }
}

unset($task);


// ==================================================
// DEADLINE HATIRLATMALARI
// ==================================================

$deadline_reminders = [];

foreach ($tasks as &$task) {

    $task["days_remaining"] = null;
    $task["deadline_label"] = "";
    $task["deadline_class"] = "";

    if (
        !empty($task["due_date"]) &&
        $task["status"] !== "onaylandı"
    ) {

        $dueDateObject = DateTime::createFromFormat(
            "Y-m-d",
            $task["due_date"]
        );

        $todayObject = new DateTime($today);

        if ($dueDateObject) {

            $difference = (int) $todayObject->diff(
                $dueDateObject
            )->format("%r%a");

            $task["days_remaining"] = $difference;

            if ($difference < 0) {

                $task["deadline_label"] =
                    "🔴 Süresi geçti";

                $task["deadline_class"] =
                    "deadline-overdue";

            } elseif ($difference === 0) {

                $task["deadline_label"] =
                    "🔴 Son gün";

                $task["deadline_class"] =
                    "deadline-today";

            } elseif ($difference === 1) {

                $task["deadline_label"] =
                    "🟠 1 gün kaldı";

                $task["deadline_class"] =
                    "deadline-tomorrow";

            } elseif ($difference <= 3) {

                $task["deadline_label"] =
                    "🟡 " . $difference . " gün kaldı";

                $task["deadline_class"] =
                    "deadline-soon";

            }

            if ($difference <= 3) {

                $deadline_reminders[] = [
                    "task_id" => (int) $task["id"],
                    "title" => $task["title"],
                    "user_name" => $task["user_name"],
                    "due_date" => $task["due_date"],
                    "days_remaining" => $difference,
                    "label" => $task["deadline_label"],
                    "class" => $task["deadline_class"]
                ];
            }
        }
    }
}

unset($task);


// ==================================================
// RAPOR İSTATİSTİKLERİ
// ==================================================

$report_total = count($tasks);
$report_pending = 0;
$report_review = 0;
$report_revision = 0;
$report_approved = 0;
$report_expired = 0;
$report_priority = [
    "urgent" => 0,
    "high" => 0,
    "normal" => 0,
    "low" => 0
];

$user_task_stats = [];

foreach ($tasks as $task) {

    switch ($task["status"]) {

        case "bekliyor":
            $report_pending++;
            break;

        case "incelemede":
            $report_review++;
            break;

        case "revizyon":
            $report_revision++;
            break;

        case "onaylandı":
            $report_approved++;
            break;
    }

    if (!empty($task["is_expired"])) {
        $report_expired++;
    }

    $taskPriority = $task["priority"] ?? "normal";
    if (array_key_exists($taskPriority, $report_priority)) {
        $report_priority[$taskPriority]++;
    } else {
        $report_priority["normal"]++;
    }

    $userId = (int) $task["assigned_to"];

    if (!isset($user_task_stats[$userId])) {

        $user_task_stats[$userId] = [
            "name" => $task["user_name"],
            "total" => 0,
            "approved" => 0,
            "expired" => 0
        ];
    }

    $user_task_stats[$userId]["total"]++;

    if ($task["status"] === "onaylandı") {
        $user_task_stats[$userId]["approved"]++;
    }

    if (!empty($task["is_expired"])) {
        $user_task_stats[$userId]["expired"]++;
    }
}

usort(
    $user_task_stats,
    function ($a, $b) {
        return $b["total"] <=> $a["total"];
    }
);

$report_open =
    $report_total - $report_approved;

$report_completion_rate =
    $report_total > 0
        ? round(
            ($report_approved / $report_total) * 100
        )
        : 0;

$priority_chart_colors = [
    "urgent" => "#b94d68",
    "high" => "#d28a4a",
    "normal" => "#8d6bb3",
    "low" => "#6d9b7a"
];

$priority_chart_segments = [];
$priority_chart_offset = 0.0;
$priority_report_total = array_sum($report_priority);

if ($priority_report_total > 0) {
    foreach ($priority_chart_colors as $priority_key => $priority_color) {
        $priority_count = (int) ($report_priority[$priority_key] ?? 0);
        $priority_percentage = ($priority_count / $priority_report_total) * 100;
        $priority_start = round($priority_chart_offset, 2);
        $priority_chart_offset += $priority_percentage;
        $priority_end = round($priority_chart_offset, 2);

        if ($priority_count > 0) {
            $priority_chart_segments[] =
                $priority_color
                . " "
                . $priority_start
                . "% "
                . $priority_end
                . "%";
        }
    }
}

$priority_chart_background = $priority_chart_segments !== []
    ? "conic-gradient(" . implode(", ", $priority_chart_segments) . ")"
    : "conic-gradient(#e6ddec 0 100%)";

$priority_chart_background_attribute = htmlspecialchars(
    $priority_chart_background,
    ENT_QUOTES,
    "UTF-8"
);

// ==================================================
// GÖREV ÇALIŞMALARINI GETİR
// ==================================================

$stmt = $db->prepare("
    SELECT
        ts.id,
        ts.task_id,
        ts.content,
        ts.created_at,
        ts.status,
        ts.user_id,
        ts.version_no,
        t.title AS task_title,
        u.full_name AS user_name
    FROM task_submissions ts

    INNER JOIN tasks t
        ON ts.task_id = t.id

    INNER JOIN users u
        ON ts.user_id = u.id

    WHERE t.assigned_by = ?
      AND t.deleted_at IS NULL

    ORDER BY ts.created_at DESC
");

$stmt->execute([
    $admin_id
]);

$submissions =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


// ==================================================
// KULLANICI ÇALIŞMALARINI GETİR
// ==================================================

$stmt = $db->prepare("
    SELECT
        ua.id,
        ua.title,
        ua.description,
        ua.file_path,
        ua.created_at,
        ua.user_id,
        u.full_name AS user_name
    FROM user_activities ua

    INNER JOIN users u
        ON ua.user_id = u.id

    ORDER BY ua.created_at DESC
");

$stmt->execute();

$user_activities =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


// ==================================================
// ADMİN BİLDİRİMLERİ
// ==================================================

$stmt = $db->prepare("
    SELECT
        id,
        title,
        message,
        is_read,
        created_at
    FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC
");

$stmt->execute([
    $admin_id
]);

$notifications =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


// ==================================================
// OKUNMAMIŞ BİLDİRİM
// ==================================================

$stmt = $db->prepare("
    SELECT COUNT(*)
    FROM notifications
    WHERE user_id = ?
    AND is_read = 0
");

$stmt->execute([
    $admin_id
]);

$unread_count =
    (int) $stmt->fetchColumn();


$profile_image_url = null;
$normalized_profile_path = str_replace(
    "\\",
    "/",
    ltrim((string) ($profile_image ?? ""), "/")
);
$profile_root = realpath(__DIR__ . "/../uploads/profile_images");
$profile_candidate = realpath(
    __DIR__ . "/../" . $normalized_profile_path
);

if (
    $normalized_profile_path !== "" &&
    strpos($normalized_profile_path, "..") === false &&
    strpos($normalized_profile_path, "uploads/profile_images/") === 0 &&
    $profile_root !== false &&
    $profile_candidate !== false &&
    strpos(
        $profile_candidate,
        $profile_root . DIRECTORY_SEPARATOR
    ) === 0
) {
    $profile_image_url = "../" . $normalized_profile_path;
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

<title>Admin Paneli - Todo App</title>

<link
    rel="stylesheet"
    href="../assets/css/style.css?v=upload-card-lavanta-20260819"
>


</head>


<body class="reference-admin-layout" data-initial-section="<?= htmlspecialchars($active_section, ENT_QUOTES, "UTF-8") ?>">


<!-- ==================================================
     SIDEBAR
================================================== -->

<div class="sidebar">

    <h2>TODO APP</h2>

    <div class="sidebar-profile-summary">

        <div class="sidebar-profile-avatar">

            <?php if (!empty($profile_image_url)): ?>

                <img
                    src="<?= htmlspecialchars(
                        $profile_image_url,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    alt="Admin profil fotoğrafı"
                >

            <?php else: ?>

                <span
                    class="profile-placeholder"
                    aria-hidden="true"
                ></span>

            <?php endif; ?>

        </div>

        <strong class="sidebar-profile-name">
            <?= htmlspecialchars(
                $_SESSION["full_name"] ?? "Admin",
                ENT_QUOTES,
                "UTF-8"
            ) ?>
        </strong>

        <span class="sidebar-profile-role">Yönetici</span>

    </div>

    <a
        href="#profil"
        class="menu-link"
        data-section="profil"
    >
        👤 Profil
    </a>

    <nav class="sidebar-navigation" aria-label="Yönetici menüsü">

        <a
            href="#dashboard"
            class="menu-link active"
            data-section="dashboard"
        >
            <span aria-hidden="true">📊</span>
            Dashboard
        </a>

        <a
            href="#raporlar"
            class="menu-link"
            data-section="raporlar"
        >
            <span aria-hidden="true">📊</span>
            Raporlar
        </a>

        <a
            href="raporlar.php"
            class="menu-link"
        >
            <span aria-hidden="true">📈</span>
            Rapor Dışa Aktar
        </a>

        <a
            href="#kullanicilar"
            class="menu-link"
            data-section="kullanicilar"
        >
            <span aria-hidden="true">👥</span>
            Kullanıcılar
        </a>

        <a
            href="#gorev-ata"
            class="menu-link"
            data-section="gorev-ata"
        >
            <span aria-hidden="true">➕</span>
            Görev Ata
        </a>

        <a
            href="#gorevler"
            class="menu-link"
            data-section="gorevler"
        >
            <span aria-hidden="true">📋</span>
            Görevler
        </a>

        <a
            href="arsiv.php"
            class="menu-link"
        >
            <span aria-hidden="true">🗃️</span>
            Görev Arşivi
        </a>

        <a
            href="#calismalar"
            class="menu-link"
            data-section="calismalar"
        >
            <span aria-hidden="true">📤</span>
            Görev Çalışmaları
        </a>

        <a
            href="#kullanici-calismalari"
            class="menu-link"
            data-section="kullanici-calismalari"
        >
            <span aria-hidden="true">📝</span>
            Kullanıcı Çalışmaları
        </a>

        <a
            href="#bildirimler"
            class="menu-link"
            data-section="bildirimler"
        >
            <span aria-hidden="true">🔔</span>
            Bildirimler

            <?php if ($unread_count > 0): ?>

                <span class="notification-count sidebar-notification-count">
                    <?= (int) $unread_count ?>
                </span>

            <?php endif; ?>

        </a>

        <a
            href="giris_loglari.php"
            class="sidebar-page-link"
        >
            <span aria-hidden="true">🧾</span>
            Giriş Logları
        </a>

    </nav>

    <a
        href="../logout.php"
        class="logout"
    >
        <span aria-hidden="true">🚪</span>
        Çıkış Yap
    </a>

</div>



<!-- ==================================================
     ANA İÇERİK
================================================== -->

<div class="main">

<div class="container">


<!-- ==================================================
     DASHBOARD
================================================== -->

<section
    id="section-dashboard"
    class="panel-section active-section"
>

    <div class="page-header">

        <h1>
            Admin Paneli
        </h1>
 




        <p>

            Hoş geldin,

            <strong>
                <?= htmlspecialchars(
                    $_SESSION["full_name"] ?? ""
                ) ?>
            </strong>

            👋

        </p>

    </div>


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


    <div class="dashboard-cards">


        <div class="box dashboard-card">

            <h3>
                👥 Kullanıcılar
            </h3>

            <h1>
                <?= count($all_users) ?>
            </h1>

        </div>


        <div class="box dashboard-card">

            <h3>
                📋 Görevler
            </h3>

            <h1>
                <?= count($tasks) ?>
            </h1>

        </div>


        <div class="box dashboard-card">

            <h3>
                📤 Görev Çalışmaları
            </h3>

            <h1>
                <?= count($submissions) ?>
            </h1>

        </div>


        <div class="box dashboard-card">

            <h3>
                📝 Kullanıcı Çalışmaları
            </h3>

            <h1>
                <?= count($user_activities) ?>
            </h1>

        </div>


    </div>


    <!-- ==================================================
         DEADLINE HATIRLATMALARI
    ================================================== -->

    <div class="box deadline-reminder-panel">

        <div class="section-title deadline-panel-title">

            <div>
                <h2>
                    ⏰ Yaklaşan Deadline'lar
                </h2>

                <p>
                    Son 3 gün içinde olan ve süresi geçmiş görevler.
                </p>
            </div>

            <span class="deadline-count">
                <?= count($deadline_reminders) ?>
            </span>

        </div>


        <?php if (empty($deadline_reminders)): ?>

            <div class="deadline-empty">
                ✅ Şu anda yaklaşan veya süresi geçmiş görev bulunmuyor.
            </div>

        <?php else: ?>

            <div class="deadline-list">

                <?php foreach ($deadline_reminders as $reminder): ?>

                    <div class="deadline-item <?= htmlspecialchars($reminder["class"]) ?>">

                        <div class="deadline-item-main">

                            <strong>
                                <?= htmlspecialchars($reminder["title"]) ?>
                            </strong>

                            <span>
                                👤
                                <?= htmlspecialchars($reminder["user_name"]) ?>
                            </span>

                        </div>

                        <div class="deadline-item-meta">

                            <span>
                                📅
                                <?= htmlspecialchars($reminder["due_date"]) ?>
                            </span>

                            <span class="deadline-label">
                                <?= htmlspecialchars($reminder["label"]) ?>
                            </span>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>


    <div
        class="box"
        style="margin-top:30px;"
    >

        <h2>
            👋 Hoş Geldiniz
        </h2>

        <p>
            Sol menüden yapmak istediğiniz işlemi seçebilirsiniz.
        </p>

        <p>
            Kullanıcı ekleyebilir, kullanıcı bilgilerini
            düzenleyebilir veya silebilir, görev atayabilir,
            görevleri düzenleyebilir veya silebilir,
            görev çalışmalarını inceleyebilir
            ve bildirimlerinizi kontrol edebilirsiniz.
        </p>

    </div>

</section>



<!-- ==================================================
     PROFİL
================================================== -->

<section
    id="section-profil"
    class="panel-section"
>
    <div class="section-title user-profile-page-heading">
        <h1>Profilim</h1>
        <p>Hesap bilgilerinizi ve profil fotoğrafınızı buradan yönetebilirsiniz.</p>
    </div>

    <div class="box user-profile-tab">
        <div class="user-profile-tab-header">
            <div class="user-profile-tab-avatar">
                <?php if (!empty($profile_image_url)): ?>
                    <img
                        src="<?= htmlspecialchars($profile_image_url, ENT_QUOTES, "UTF-8") ?>"
                        alt="Admin profil fotoğrafı"
                    >
                <?php else: ?>
                    <span class="profile-placeholder" aria-hidden="true"></span>
                <?php endif; ?>
            </div>

            <div class="user-profile-tab-identity">
                <span class="user-profile-tab-eyebrow">HESAP BİLGİLERİ</span>
                <h2><?= htmlspecialchars($_SESSION["full_name"] ?? "Admin", ENT_QUOTES, "UTF-8") ?></h2>
                <span class="profile-role-badge">Yönetici</span>
                <p>Kullanıcıları, görevleri, çalışmaları ve bildirimleri bu alandan yönetebilirsiniz.</p>
            </div>
        </div>

        <?php if (!empty($profile_update_message)): ?>
            <div class="success user-profile-tab-message">
                <?= htmlspecialchars($profile_update_message, ENT_QUOTES, "UTF-8") ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($profile_error_message)): ?>
            <div class="error user-profile-tab-message">
                <?= nl2br(htmlspecialchars($profile_error_message, ENT_QUOTES, "UTF-8")) ?>
            </div>
        <?php endif; ?>

        <div class="user-profile-tab-divider"></div>

        <div class="user-profile-tab-actions">
            <form method="POST" enctype="multipart/form-data" class="profile-upload-form profile-page-upload-form">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, "UTF-8") ?>"
                >
                <input type="hidden" name="update_profile_image" value="1">

                <div class="upload-field-heading">
                    <span>Profil Fotoğrafı</span>
                    <small>JPG, JPEG veya PNG · Maks. 5 MB</small>
                </div>

                <label
                    for="adminProfileImageInput"
                    class="upload-dropzone profile-upload-dropzone"
                    data-upload-dropzone
                >
                    <span class="upload-dropzone-icon" aria-hidden="true">+</span>
                    <span class="upload-dropzone-copy">
                        <strong>Fotoğraf yüklemek için tıklayın veya sürükleyin</strong>
                        <small>İzin verilen uzantılar: JPG, JPEG, PNG · En fazla 5 MB</small>
                        <span
                            class="upload-dropzone-selection"
                            id="adminProfileFileName"
                            data-upload-file-summary
                            hidden
                        >
                            Henüz dosya seçilmedi
                        </span>
                    </span>
                </label>

                <input
                    type="file"
                    id="adminProfileImageInput"
                    name="profile_image"
                    accept=".jpg,.jpeg,.png"
                    class="profile-file-input profile-page-file-input upload-input"
                    data-upload-input
                >

                <button
                    type="submit"
                    id="adminProfileUploadSubmit"
                    class="profile-action-button success profile-page-upload-submit"
                    hidden
                >
                    Fotoğrafı Yükle
                </button>
            </form>

            <?php if (!empty($profile_image)): ?>
                <form
                    method="POST"
                    class="profile-delete-form profile-page-delete-form"
                    onsubmit="return confirm('Profil fotoğrafını silmek istediğinize emin misiniz?');"
                >
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, "UTF-8") ?>"
                    >
                    <input type="hidden" name="delete_profile_image" value="1">
                    <button
                        type="submit"
                        class="profile-action-button danger profile-page-delete-button"
                    >
                        🗑 Fotoğrafı Sil
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <a
            href="#dashboard"
            class="profile-back-link profile-page-back menu-link"
            data-section="dashboard"
        >
            ← Dashboard'a Dön
        </a>
    </div>
</section>

<script>
const adminProfileImageInput =
    document.getElementById("adminProfileImageInput");
const adminProfileUploadSubmit =
    document.querySelector(".profile-page-upload-submit");
const adminProfileFileName =
    document.getElementById("adminProfileFileName");

if (adminProfileImageInput && adminProfileUploadSubmit) {
    adminProfileImageInput.addEventListener("change", function () {
        const hasFile = this.files && this.files.length > 0;

        adminProfileUploadSubmit.hidden = !hasFile;

        if (adminProfileFileName) {
            adminProfileFileName.textContent = hasFile
                ? this.files[0].name
                : "Henüz dosya seçilmedi";
            adminProfileFileName.hidden = !hasFile;
        }
    });
}

const adminProfileDropzone = document.querySelector(
    'label[data-upload-dropzone][for="adminProfileImageInput"]'
);

if (adminProfileImageInput && adminProfileDropzone) {
    ["dragenter", "dragover"].forEach(function (eventName) {
        adminProfileDropzone.addEventListener(eventName, function (event) {
            event.preventDefault();
            event.stopPropagation();
            adminProfileDropzone.classList.add("is-dragover");
        });
    });

    ["dragleave", "dragend"].forEach(function (eventName) {
        adminProfileDropzone.addEventListener(eventName, function (event) {
            event.preventDefault();
            event.stopPropagation();
            adminProfileDropzone.classList.remove("is-dragover");
        });
    });

    adminProfileDropzone.addEventListener("drop", function (event) {
        event.preventDefault();
        event.stopPropagation();
        adminProfileDropzone.classList.remove("is-dragover");

        const droppedFiles = event.dataTransfer && event.dataTransfer.files
            ? Array.from(event.dataTransfer.files)
            : [];

        if (droppedFiles.length === 0) {
            return;
        }

        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(droppedFiles[0]);
        adminProfileImageInput.files = dataTransfer.files;
        adminProfileImageInput.dispatchEvent(
            new Event("change", { bubbles: true })
        );
    });
}
</script>

<section
    id="section-raporlar"
    class="panel-section"
>

    <div class="section-title">

        <h1>
            📊 Görev Raporları
        </h1>

        <p>
            Görevlerin genel durumunu, deadline'larını ve kullanıcı bazlı dağılımını inceleyebilirsiniz.
        </p>

    </div>


    <!-- ÖZET -->

    <div class="report-grid">

        <div class="box report-card">
            <span>📋 Toplam Görev</span>
            <strong><?= $report_total ?></strong>
        </div>

        <div class="box report-card">
            <span>🟡 Bekleyen</span>
            <strong><?= $report_pending ?></strong>
        </div>

        <div class="box report-card">
            <span>🔵 İncelemede</span>
            <strong><?= $report_review ?></strong>
        </div>

        <div class="box report-card">
            <span>🟠 Revizyon</span>
            <strong><?= $report_revision ?></strong>
        </div>

        <div class="box report-card">
            <span>🟢 Onaylandı</span>
            <strong><?= $report_approved ?></strong>
        </div>

        <div class="box report-card report-danger">
            <span>🔴 Süresi Geçmiş</span>
            <strong><?= $report_expired ?></strong>
        </div>

    </div>


    <!-- TAMAMLANMA ORANI -->

    <div class="box report-progress-box">

        <div class="report-progress-header">

            <div>
                <h2>📈 Tamamlanma Oranı</h2>

                <p>
                    <?= $report_approved ?> / <?= $report_total ?> görev onaylandı.
                </p>
            </div>

            <strong>
                %<?= $report_completion_rate ?>
            </strong>

        </div>

        <div class="report-progress">

            <div
                class="report-progress-bar"
                style="width: <?= $report_completion_rate ?>%;"
            ></div>

        </div>

        <div class="report-open-text">
            Açık görev:
            <strong><?= $report_open ?></strong>
        </div>

    </div>


    <!-- DEADLINE RAPORU -->

    <div class="box report-priority-box">

        <div class="section-title">

            <h2>
                ⚡ Öncelik Dağılımı
            </h2>

            <p>
                Görevlerin öncelik seviyelerine göre dağılımı.
            </p>

        </div>

        <div class="report-priority-layout">

            <div
                class="priority-donut-chart"
                style="--priority-chart-background: <?= $priority_chart_background_attribute ?>;"
                role="img"
                aria-label="Öncelik dağılımı: Acil <?= (int) $report_priority["urgent"] ?>, Yüksek <?= (int) $report_priority["high"] ?>, Normal <?= (int) $report_priority["normal"] ?>, Düşük <?= (int) $report_priority["low"] ?>. Toplam <?= (int) $priority_report_total ?> görev."
            >
                <div class="priority-donut-center">
                    <strong><?= (int) $priority_report_total ?></strong>
                    <span>Toplam görev</span>
                </div>
            </div>

            <div class="report-priority-grid">
                <?php
                $priority_report_items = [
                    "urgent" => "Acil",
                    "high" => "Yüksek",
                    "normal" => "Normal",
                    "low" => "Düşük"
                ];
                ?>

                <?php foreach ($priority_report_items as $priority_key => $priority_label): ?>
                    <?php
                    $priority_count = (int) ($report_priority[$priority_key] ?? 0);
                    $priority_percentage = $priority_report_total > 0
                        ? round(($priority_count / $priority_report_total) * 100)
                        : 0;
                    ?>

                    <div class="report-priority-item priority-<?= htmlspecialchars($priority_key, ENT_QUOTES, "UTF-8") ?>">
                        <span class="report-priority-label">
                            <i class="report-priority-swatch" aria-hidden="true"></i>
                            <?= htmlspecialchars($priority_label, ENT_QUOTES, "UTF-8") ?>
                        </span>
                        <strong><?= $priority_count ?></strong>
                        <small>%<?= $priority_percentage ?></small>
                    </div>
                <?php endforeach; ?>
            </div>

        </div>

    </div>


    <div class="box">

        <div class="section-title">

            <h2>
                ⏰ Deadline Raporu
            </h2>

            <p>
                Yaklaşan ve süresi geçmiş görevlerin özeti.
            </p>

        </div>


        <?php if (empty($deadline_reminders)): ?>

            <div class="deadline-empty">
                ✅ Kritik deadline bulunmuyor.
            </div>

        <?php else: ?>

            <div class="report-deadline-summary">

                <?php

                $overdue_count = 0;
                $today_count = 0;
                $soon_count = 0;

                foreach ($deadline_reminders as $reminder) {

                    if ($reminder["days_remaining"] < 0) {
                        $overdue_count++;
                    } elseif ($reminder["days_remaining"] === 0) {
                        $today_count++;
                    } else {
                        $soon_count++;
                    }
                }

                ?>

                <div>
                    <span>🔴 Süresi Geçmiş</span>
                    <strong><?= $overdue_count ?></strong>
                </div>

                <div>
                    <span>🔴 Bugün</span>
                    <strong><?= $today_count ?></strong>
                </div>

                <div>
                    <span>🟡 Son 3 Gün</span>
                    <strong><?= $soon_count ?></strong>
                </div>

            </div>

        <?php endif; ?>

    </div>


    <!-- KULLANICI BAZLI RAPOR -->

    <div class="box">

        <div class="section-title">

            <h2>
                👥 Kullanıcı Bazlı Görev Raporu
            </h2>

            <p>
                Her kullanıcıya atanan görevlerin genel durumu.
            </p>

        </div>


        <?php if (empty($user_task_stats)): ?>

            <div class="empty">
                Henüz raporlanacak görev bulunmuyor.
            </div>

        <?php else: ?>

            <div class="report-user-list">

                <?php foreach ($user_task_stats as $stat): ?>

                    <div class="report-user-row">

                        <div class="report-user-info">

                            <strong>
                                👤 <?= htmlspecialchars($stat["name"]) ?>
                            </strong>

                            <span>
                                <?= $stat["total"] ?> görev
                            </span>

                        </div>

                        <div class="report-user-stats">

                            <span class="report-badge">
                                🟢 <?= $stat["approved"] ?> onaylandı
                            </span>

                            <span class="report-badge report-badge-danger">
                                🔴 <?= $stat["expired"] ?> süresi geçti
                            </span>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

</section>


<!-- ==================================================
     KULLANICILAR
================================================== -->

<section
    id="section-kullanicilar"
    class="panel-section"
>

    <div class="section-title">

        <h1>
            👥 Kullanıcı Yönetimi
        </h1>

        <p>
            Kullanıcıları ve adminleri görüntüleyebilir,
            düzenleyebilir ve yönetebilirsiniz.
        </p>

    </div>


    <!-- ==================================================
         KULLANICI EKLE
    ================================================== -->

    <div class="box">

        <h2>
            ➕ Yeni Kullanıcı Ekle
        </h2>

        <p style="opacity:.7;">

            Yeni kullanıcılar otomatik olarak

            <strong>user</strong>

            rolüyle oluşturulur.

        </p>


        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $csrf_token
                ) ?>"
            >


            <div class="form-group">

                <label>
                    Ad Soyad
                </label>

                <input
                    type="text"
                    name="full_name"
                    placeholder="Örn: Naz Öcal"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    Kullanıcı Adı
                </label>

                <input
                    type="text"
                    name="username"
                    placeholder="Örn: nazocal"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    Şifre
                </label>

                <input
                    type="password"
                    name="password"
                    placeholder="En az 6 karakter"
                    required
                >

            </div>


            <button
                type="submit"
                name="add_user"
                class="full-width"
            >

                👤 Kullanıcı Oluştur

            </button>

        </form>

    </div>



    <!-- ==================================================
         TÜM KULLANICILAR + ADMİNLER
    ================================================== -->

    <div
        class="box"
        style="margin-top:25px;"
    >

        <h2>
            👥 Sistemdeki Kullanıcılar
        </h2>

        <p style="opacity:.7;">
            Adminler ve normal kullanıcılar burada görüntülenir.
        </p>

        <div class="filter-box">
            <div class="filter-grid">
                <div class="filter-group">
                    <label for="userSearch">🔎 Kullanıcı Ara</label>
                    <input type="search" id="userSearch" placeholder="Ad, soyad veya kullanıcı adı...">
                </div>
                <div class="filter-group">
                    <label for="userRoleFilter">🛡️ Rol</label>
                    <select id="userRoleFilter">
                        <option value="all">Tüm Roller</option>
                        <option value="admin">Admin</option>
                        <option value="user">User</option>
                    </select>
                </div>
                <button type="button" class="filter-reset" id="resetUserFilter">↺ Filtreleri Temizle</button>
            </div>
            <div class="filter-result-count" id="userFilterCount"></div>
        </div>

        <?php if (empty($all_users)): ?>

            <div class="empty">

                <h3>
                    Henüz kullanıcı bulunmuyor.
                </h3>

            </div>

        <?php else: ?>


            <div class="user-list">


                <?php foreach ($all_users as $user): ?>


                    <div
                        class="user-card filter-item user-filter-item <?= $user["role"] === "admin"
                            ? "admin-card"
                            : "" ?>"
                        data-user-name="<?= htmlspecialchars(
                            strtolower($user["full_name"] . " " . $user["username"]),
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                        data-user-role="<?= htmlspecialchars($user["role"], ENT_QUOTES, "UTF-8") ?>"
                    >


                        <h3>

                            <?= $user["role"] === "admin"
                                ? "🛡️"
                                : "👤" ?>

                            <?= htmlspecialchars(
                                $user["full_name"]
                            ) ?>

                        </h3>


                        <p>

                            @<?= htmlspecialchars(
                                $user["username"]
                            ) ?>

                        </p>


                        <span
                            class="user-role <?= $user["role"] === "admin"
                                ? "admin"
                                : "user" ?>"
                        >

                            <?= $user["role"] === "admin"
                                ? "🛡️ ADMIN"
                                : "👤 USER" ?>

                        </span>



                        <!-- ==================================================
                             KULLANICI İŞLEMLERİ
                        ================================================== -->

                        <div class="user-actions">


                            <button
                                type="button"
                                class="secondary-button edit-user-button"
                                data-user-id="<?= (int) $user["id"] ?>"
                            >

                                ✏️ Düzenle

                            </button>


                            <?php if (
                                (int) $user["id"] !== $admin_id
                            ): ?>


                                <form
                                    method="POST"
                                    class="delete-user-form"
                                    data-user-name="<?= htmlspecialchars(
                                        $user["full_name"],
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars(
                                            $csrf_token
                                        ) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="user_id"
                                        value="<?= (int) $user["id"] ?>"
                                    >

                                    <button
                                        type="submit"
                                        name="delete_user"
                                        class="delete-button"
                                    >

                                        🗑️ Sil

                                    </button>

                                </form>


                            <?php else: ?>

                                <span
                                    style="
                                        opacity:.55;
                                        padding:10px 5px;
                                        font-size:13px;
                                    "
                                >

                                    🔒 Siz

                                    <strong>
                                        kendi hesabınızsınız
                                    </strong>

                                </span>

                            <?php endif; ?>


                        </div>



                        <!-- ==================================================
                             KULLANICI DÜZENLEME FORMU
                        ================================================== -->

                        <div
                            class="user-edit-box"
                            id="edit-user-<?= (int) $user["id"] ?>"
                        >

                            <h3>
                                ✏️ Kullanıcıyı Düzenle
                            </h3>


                            <form method="POST">

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= htmlspecialchars(
                                        $csrf_token
                                    ) ?>"
                                >


                                <input
                                    type="hidden"
                                    name="user_id"
                                    value="<?= (int) $user["id"] ?>"
                                >


                                <div class="form-group">

                                    <label>
                                        Ad Soyad
                                    </label>

                                    <input
                                        type="text"
                                        name="edit_full_name"
                                        value="<?= htmlspecialchars(
                                            $user["full_name"]
                                        ) ?>"
                                        required
                                    >

                                </div>


                                <div class="form-group">

                                    <label>
                                        Kullanıcı Adı
                                    </label>

                                    <input
                                        type="text"
                                        name="edit_username"
                                        value="<?= htmlspecialchars(
                                            $user["username"]
                                        ) ?>"
                                        required
                                    >

                                </div>


                                <div class="form-group">

                                    <label>
                                        Yeni Şifre
                                    </label>

                                    <input
                                        type="password"
                                        name="edit_password"
                                        placeholder="Değiştirmek istemiyorsanız boş bırakın"
                                    >

                                    <small
                                        style="opacity:.6;"
                                    >
                                        Şifre değiştirmeyecekseniz boş bırakın.
                                    </small>

                                </div>


                                <div class="form-group">

                                    <label>
                                        Rol
                                    </label>

                                    <select
                                        name="edit_role"
                                        <?= (int) $user["id"] === $admin_id
                                            ? "disabled"
                                            : "" ?>
                                    >

                                        <option
                                            value="user"
                                            <?= $user["role"] === "user"
                                                ? "selected"
                                                : "" ?>
                                        >
                                            👤 User
                                        </option>

                                        <option
                                            value="admin"
                                            <?= $user["role"] === "admin"
                                                ? "selected"
                                                : "" ?>
                                        >
                                            🛡️ Admin
                                        </option>

                                    </select>


                                    <?php if (
                                        (int) $user["id"] === $admin_id
                                    ): ?>

                                        <input
                                            type="hidden"
                                            name="edit_role"
                                            value="admin"
                                        >

                                        <small
                                            style="
                                                display:block;
                                                margin-top:7px;
                                                opacity:.6;
                                            "
                                        >
                                            Kendi admin rolünüz değiştirilemez.
                                        </small>

                                    <?php endif; ?>

                                </div>


                                <div class="task-actions">

                                    <button
                                        type="submit"
                                        name="edit_user"
                                    >

                                        💾 Değişiklikleri Kaydet

                                    </button>


                                    <button
                                        type="button"
                                        class="cancel-user-edit"
                                        data-user-id="<?= (int) $user["id"] ?>"
                                    >

                                        ✖ İptal

                                    </button>

                                </div>

                            </form>

                        </div>


                    </div>


                <?php endforeach; ?>


            </div>


        <?php endif; ?>

    </div>

</section>



<!-- ==================================================
     GÖREV ATA
================================================== -->

<section
    id="section-gorev-ata"
    class="panel-section"
>

    <div class="section-title">

        <h1>
            ➕ Yeni Görev Ata
        </h1>

        <p>
            Kullanıcılara veya diğer adminlere yeni görevler oluşturabilirsiniz.
        </p>

    </div>


    <div class="box">

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $csrf_token
                ) ?>"
            >


            <div class="form-group">

                <label>
                    Görev Başlığı
                </label>

                <input
                    type="text"
                    name="title"
                    placeholder="Örn: Login sayfasını tamamla"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    Görev Açıklaması
                </label>

                <textarea
                    name="description"
                    placeholder="Görevin detaylarını yazın..."
                    required
                ></textarea>

            </div>


            <div class="form-group">

                <label>
                    Kullanıcı
                </label>

                <select
                    name="assigned_to"
                    required
                >

                    <option value="">
                        Kullanıcı veya Admin seçin
                    </option>

                    <?php foreach ($users as $user): ?>

                        <option
                            value="<?= (int) $user["id"] ?>"
                        >

                            <?= htmlspecialchars(
                                $user["full_name"]
                            ) ?>

                            -

                            <?= htmlspecialchars(
                                $user["username"]
                            ) ?>

                            <?= $user["role"] === "admin" ? " (Admin)" : "" ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="form-group">

                <label>
                    Son Tarih
                </label>

                <input
                    type="date"
                    name="due_date"
                    required
                >

            </div>

            <div class="form-group">
                <label for="taskPriority">Öncelik</label>
                <select id="taskPriority" name="priority" required>
                    <option value="low">Düşük</option>
                    <option value="normal" selected>Normal</option>
                    <option value="high">Yüksek</option>
                    <option value="urgent">Acil</option>
                </select>
            </div>


            <button
                type="submit"
                name="create_task"
                class="full-width"
            >

                🚀 Görevi Ata

            </button>

        </form>

    </div>

</section>



<!-- ==================================================
     GÖREVLER
================================================== -->

<section
    id="section-gorevler"
    class="panel-section"
>

    <div class="section-title">

        <h1>
            📋 Görevler
        </h1>

        <p>
            Atadığınız görevleri takip edebilir,
            düzenleyebilir veya silebilirsiniz.
        </p>

    </div>

    <div class="filter-box">
        <div class="filter-grid">
            <div class="filter-group">
                <label for="taskSearch">🔎 Görev Ara</label>
                <input type="search" id="taskSearch" placeholder="Görev başlığı veya açıklama...">
            </div>
            <div class="filter-group">
                <label for="taskUserFilter">👤 Kullanıcı</label>
                <select id="taskUserFilter">
                    <option value="all">Tüm Kullanıcılar</option>
                    <?php foreach ($users as $filter_user): ?>
                        <option value="<?= (int) $filter_user["id"] ?>">
                            <?= htmlspecialchars($filter_user["full_name"]) ?>
                            <?= $filter_user["role"] === "admin" ? " (Admin)" : "" ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="taskStatusFilter">📌 Durum</label>
                <select id="taskStatusFilter">
                    <option value="all">Tüm Durumlar</option>
                    <option value="bekliyor">Bekliyor</option>
                    <option value="incelemede">İncelemede</option>
                    <option value="revizyon">Revizyon</option>
                    <option value="onaylandı">Onaylandı</option>
                    <option value="expired">Süresi Doldu</option>
                </select>
            </div>
            <div class="filter-group">
                <label for="taskPriorityFilter">⚡ Öncelik</label>
                <select id="taskPriorityFilter">
                    <option value="all">Tüm Öncelikler</option>
                    <option value="urgent">Acil</option>
                    <option value="high">Yüksek</option>
                    <option value="normal">Normal</option>
                    <option value="low">Düşük</option>
                </select>
            </div>
            <button type="button" class="filter-reset" id="resetTaskFilter">↺ Filtreleri Temizle</button>
        </div>
        <div class="filter-result-count" id="taskFilterCount"></div>
    </div>

    <div class="filter-empty" id="taskFilterEmpty">🔎 Filtrelere uygun görev bulunamadı.</div>
    <div class="pagination" id="taskPagination" aria-label="Görev sayfaları"></div>

    <?php if (empty($tasks)): ?>

        <div class="empty">

            <h2>
                Henüz görev oluşturmadınız.
            </h2>

            <p>
                Görev Ata bölümünden yeni görev oluşturabilirsiniz.
            </p>

        </div>

    <?php else: ?>


        <?php foreach ($tasks as $task): ?>

            <div
                class="box filter-item task-filter-item"
                data-task-title="<?= htmlspecialchars(strtolower($task["title"] . " " . $task["description"]), ENT_QUOTES, "UTF-8") ?>"
                data-task-user="<?= (int) $task["assigned_to"] ?>"
                data-task-status="<?= !empty($task["is_expired"]) ? "expired" : htmlspecialchars($task["status"], ENT_QUOTES, "UTF-8") ?>"
                data-task-priority="<?= htmlspecialchars($task["priority"] ?? "normal", ENT_QUOTES, "UTF-8") ?>"
            >

                <?php
                $priority_labels = ["low" => "Düşük", "normal" => "Normal", "high" => "Yüksek", "urgent" => "Acil"];
                $task_priority = $task["priority"] ?? "normal";
                ?>
                <span class="priority-badge priority-<?= htmlspecialchars($task_priority, ENT_QUOTES, "UTF-8") ?>">
                    <?= htmlspecialchars($priority_labels[$task_priority] ?? "Normal", ENT_QUOTES, "UTF-8") ?>
                </span>

                <h2>

                    📌

                    <?= htmlspecialchars(
                        $task["title"]
                    ) ?>

                </h2>


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


                                <p>

                    <strong>
                        👤 Atanan Kullanıcı:
                    </strong>

                    <?= htmlspecialchars(
                        $task["user_name"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                    <?php if (!empty($task["username"])): ?>

                        <span style="opacity:.6;">
                            (@<?= htmlspecialchars(
                                $task["username"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>)
                        </span>

                    <?php endif; ?>

                </p>


                <p>

                    <strong>
                        🧑‍💼 Atayan:
                    </strong>

                    <?= htmlspecialchars(
                        $task["assigned_by_name"] ?? "-",
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                    <?php if (!empty($task["assigned_by_username"])): ?>

                        <span style="opacity:.6;">
                            (@<?= htmlspecialchars(
                                $task["assigned_by_username"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>)
                        </span>

                    <?php endif; ?>

                </p>


                <p class="date">

                    <strong>
                        📅 Son Tarih:
                    </strong>

                    <?= htmlspecialchars(
                        $task["due_date"] ?? "-"
                    ) ?>

                </p>


                <p>

                    <strong>
                        Durum:
                    </strong>


                    <?php

                    if (!empty($task["is_expired"])) {

                        $status_class =
                            "status expired";

                        $display_status =
                            "🔴 Süresi Doldu";

                    } else {

                        $status_class =
                            "status";

                        $display_status =
                            $task["status"];


                        if (
                            $task["status"] === "bekliyor"
                        ) {

                            $status_class .=
                                " bekliyor";

                            $display_status =
                                "Bekliyor";

                        } elseif (
                            $task["status"] === "incelemede"
                        ) {

                            $status_class .=
                                " incelemede";

                            $display_status =
                                "İncelemede";

                        } elseif (
                            $task["status"] === "revizyon"
                        ) {

                            $status_class .=
                                " revize";

                            $display_status =
                                "Revizyon";

                        } elseif (
                            $task["status"] === "onaylandı"
                        ) {

                            $status_class .=
                                " onaylandi";

                            $display_status =
                                "Onaylandı";
                        }
                    }

                    ?>


                    <span
                        class="<?= $status_class ?>"
                    >

                        <?= htmlspecialchars(
                            $display_status
                        ) ?>

                    </span>

                </p>



                <!-- ==================================================
                     GÖREV İŞLEMLERİ
                ================================================== -->

                <div class="task-actions">


                    <button
                        type="button"
                        class="secondary-button edit-task-button"
                        data-task-id="<?= (int) $task["id"] ?>"
                    >

                        ✏️ Görevi Düzenle

                    </button>


                    <!-- GÖREV SİL -->

                    <form
                        method="POST"
                        onsubmit="
                            return confirm(
                                'Bu görevi silmek istediğinize emin misiniz? Göreve ait çalışmalar da silinecektir.'
                            );
                        "
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(
                                $csrf_token
                            ) ?>"
                        >

                        <input
                            type="hidden"
                            name="task_id"
                            value="<?= (int) $task["id"] ?>"
                        >

                        <button
                            type="submit"
                            name="delete_task"
                            class="delete-button"
                        >

                            🗑️ Görevi Sil

                        </button>

                    </form>



                    <?php if (
                        $task["status"] === "incelemede" &&
                        empty($task["is_expired"])
                    ): ?>


                        <!-- ONAY -->

                        <form method="POST">

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars(
                                    $csrf_token
                                ) ?>"
                            >

                            <input
                                type="hidden"
                                name="task_id"
                                value="<?= (int) $task["id"] ?>"
                            >

                            <button
                                type="submit"
                                name="approve_task"
                                onclick="
                                    return confirm(
                                        'Bu görevi onaylamak istediğinize emin misiniz?'
                                    );
                                "
                            >

                                ✅ Onayla

                            </button>

                        </form>


                        <!-- REVİZYON -->

                        <form method="POST">

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars(
                                    $csrf_token
                                ) ?>"
                            >

                            <input
                                type="hidden"
                                name="task_id"
                                value="<?= (int) $task["id"] ?>"
                            >

                            <button
                                type="submit"
                                name="revision_task"
                                onclick="
                                    return confirm(
                                        'Bu görev için revizyon istemek istediğinize emin misiniz?'
                                    );
                                "
                            >

                                🔄 Revizyon İste

                            </button>

                        </form>


                    <?php endif; ?>


                </div>



                <!-- ==================================================
                     GÖREV DÜZENLEME FORMU
                ================================================== -->

                <div
                    class="edit-task-box"
                    id="edit-task-<?= (int) $task["id"] ?>"
                >

                    <h3>
                        ✏️ Görevi Düzenle
                    </h3>


                    <form method="POST">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(
                                $csrf_token
                            ) ?>"
                        >


                        <input
                            type="hidden"
                            name="task_id"
                            value="<?= (int) $task["id"] ?>"
                        >


                        <div class="form-group">

                            <label>
                                Görev Başlığı
                            </label>

                            <input
                                type="text"
                                name="edit_title"
                                value="<?= htmlspecialchars(
                                    $task["title"]
                                ) ?>"
                                required
                            >

                        </div>


                        <div class="form-group">

                            <label>
                                Görev Açıklaması
                            </label>

                            <textarea
                                name="edit_description"
                                required
                            ><?= htmlspecialchars(
                                $task["description"]
                            ) ?></textarea>

                        </div>


                        <div class="form-group">

                            <label>
                                Atanan Kullanıcı
                            </label>

                            <select
                                name="edit_assigned_to"
                                required
                            >

                                <?php foreach ($users as $user): ?>

                                    <option
                                        value="<?= (int) $user["id"] ?>"
                                        <?= (
                                            (int) $user["id"] ===
                                            (int) $task["assigned_to"]
                                        )
                                            ? "selected"
                                            : ""
                                        ?>
                                    >

                                        <?= htmlspecialchars(
                                            $user["full_name"]
                                        ) ?>

                                        -

                                        <?= htmlspecialchars(
                                            $user["username"]
                                        ) ?>

                                        <?= $user["role"] === "admin" ? " (Admin)" : "" ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="form-group">

                            <label>
                                Son Tarih
                            </label>

                            <input
                                type="date"
                                name="edit_due_date"
                                value="<?= htmlspecialchars(
                                    $task["due_date"]
                                ) ?>"
                                required
                            >

                        </div>


                        <div class="form-group">
                            <label for="editTaskPriority<?= (int) $task["id"] ?>">Öncelik</label>
                            <select id="editTaskPriority<?= (int) $task["id"] ?>" name="edit_priority" required>
                                <?php foreach (["low" => "Düşük", "normal" => "Normal", "high" => "Yüksek", "urgent" => "Acil"] as $priority_value => $priority_label): ?>
                                    <option value="<?= $priority_value ?>" <?= ($task["priority"] ?? "normal") === $priority_value ? "selected" : "" ?>><?= $priority_label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="task-actions">

                            <button
                                type="submit"
                                name="edit_task"
                            >

                                💾 Değişiklikleri Kaydet

                            </button>


                            <button
                                type="button"
                                class="cancel-edit"
                                data-task-id="<?= (int) $task["id"] ?>"
                            >

                                ✖ İptal

                            </button>

                        </div>

                    </form>

                </div>



                <?php if (
                    !empty($task["is_expired"])
                ): ?>

                    <div class="expired-warning">

                        ⏰ Bu görevin son tarihi geçmiştir.

                        <br>

                        Artık onay veya revizyon işlemi yapılamaz.

                    </div>

                <?php endif; ?>


            </div>

        <?php endforeach; ?>


    <?php endif; ?>

</section>



<!-- ==================================================
     GÖREV ÇALIŞMALARI
================================================== -->

<section
    id="section-calismalar"
    class="panel-section"
>

    <div class="section-title">

        <h1>
            📤 Görev Çalışmaları
        </h1>

        <p>
            Kullanıcıların görevlere gönderdiği çalışmalar.
        </p>

    </div>

    <div class="filter-box">
        <div class="filter-grid">
            <div class="filter-group">
                <label for="submissionSearch">🔎 Görev Ara</label>
                <input type="search" id="submissionSearch" placeholder="Görev başlığı...">
            </div>
            <div class="filter-group">
                <label for="submissionUserFilter">👤 Kullanıcı</label>
                <select id="submissionUserFilter">
                    <option value="all">Tüm Kullanıcılar</option>
                    <?php foreach ($users as $filter_user): ?>
                        <option value="<?= (int) $filter_user["id"] ?>">
                            <?= htmlspecialchars($filter_user["full_name"]) ?>
                            <?= $filter_user["role"] === "admin" ? " (Admin)" : "" ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" class="filter-reset" id="resetSubmissionFilter">↺ Filtreleri Temizle</button>
        </div>
        <div class="filter-result-count" id="submissionFilterCount"></div>
    </div>

    <div class="filter-empty" id="submissionFilterEmpty">🔎 Filtrelere uygun çalışma bulunamadı.</div>

    <?php if (empty($submissions)): ?>

        <div class="empty">

            <h2>
                Henüz çalışma gönderilmedi.
            </h2>

            <p>
                Kullanıcılar görevlerine çalışma gönderdiğinde
                burada görünecek.
            </p>

        </div>

    <?php else: ?>


        <?php foreach ($submissions as $submission): ?>

            <div
                class="box filter-item submission-filter-item"
                data-submission-title="<?= htmlspecialchars(strtolower($submission["task_title"]), ENT_QUOTES, "UTF-8") ?>"
                data-submission-user="<?= (int) $submission["user_id"] ?>"
            >

                <h2>

                    📌

                    <?= htmlspecialchars(
                        $submission["task_title"]
                    ) ?>

                    <span class="submission-version-badge">
                        v<?= max(1, (int) ($submission["version_no"] ?? 1)) ?>
                    </span>

                </h2>


                <p>

                    <strong>
                        👤 Kullanıcı:
                    </strong>

                    <?= htmlspecialchars(
                        $submission["user_name"]
                    ) ?>

                </p>


                <div class="work">

                    <?= nl2br(
                        htmlspecialchars(
                            $submission["content"]
                        )
                    ) ?>

                </div>


                <div class="date">

                    📅 Gönderilme tarihi:

                    <?= htmlspecialchars(
                        $submission["created_at"]
                    ) ?>

                </div>

            </div>

        <?php endforeach; ?>


    <?php endif; ?>

</section>



<!-- ==================================================
     KULLANICI ÇALIŞMALARI
================================================== -->

<section
    id="section-kullanici-calismalari"
    class="panel-section"
>

    <div class="section-title">

        <h1>
            📝 Kullanıcı Çalışmaları
        </h1>

        <p>
            Kullanıcıların görev dışı eklediği çalışmalar.
        </p>

    </div>

    <div class="filter-box">
        <div class="filter-grid">
            <div class="filter-group">
                <label for="activitySearch">🔎 Çalışma Ara</label>
                <input type="search" id="activitySearch" placeholder="Başlık veya açıklama...">
            </div>
            <div class="filter-group">
                <label for="activityUserFilter">👤 Kullanıcı</label>
                <select id="activityUserFilter">
                    <option value="all">Tüm Kullanıcılar</option>
                    <?php foreach ($users as $filter_user): ?>
                        <option value="<?= (int) $filter_user["id"] ?>">
                            <?= htmlspecialchars($filter_user["full_name"]) ?>
                            <?= $filter_user["role"] === "admin" ? " (Admin)" : "" ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="activityFileFilter">📎 Dosya</label>
                <select id="activityFileFilter">
                    <option value="all">Tümü</option>
                    <option value="with">Dosyalı</option>
                    <option value="without">Dosyasız</option>
                </select>
            </div>
            <button type="button" class="filter-reset" id="resetActivityFilter">↺ Filtreleri Temizle</button>
        </div>
        <div class="filter-result-count" id="activityFilterCount"></div>
    </div>

    <div class="filter-empty" id="activityFilterEmpty">🔎 Filtrelere uygun kullanıcı çalışması bulunamadı.</div>

    <?php if (empty($user_activities)): ?>

        <div class="empty">

            <h2>
                Henüz kullanıcı çalışması bulunmuyor.
            </h2>

        </div>

    <?php else: ?>


        <?php foreach (
            $user_activities
            as $activity
        ): ?>


            <?php

            $original_file =
                $activity["file_path"] ?? "";

            $file_extension = "";

            if (!empty($original_file)) {

                $file_extension =
                    pathinfo(
                        $original_file,
                        PATHINFO_EXTENSION
                    );
            }


            $formatted_user_name =
                trim(
                    preg_replace(
                        '/\s+/',
                        ' ',
                        $activity["user_name"]
                    )
                );


            $formatted_task =
                trim(
                    preg_replace(
                        '/\s+/',
                        ' ',
                        $activity["title"]
                    )
                );


            $formatted_date =
                date(
                    "d.m.Y_H-i-s",
                    strtotime(
                        $activity["created_at"]
                    )
                );


            if (!empty($file_extension)) {

                $display_file_name =
                    $formatted_task .
                    "_" .
                    $formatted_user_name .
                    "_" .
                    $formatted_date .
                    "." .
                    $file_extension;

            } else {

                $display_file_name =
                    $formatted_task .
                    "_" .
                    $formatted_user_name .
                    "_" .
                    $formatted_date;
            }

            ?>


            <div
                class="box filter-item activity-filter-item"
                data-activity-title="<?= htmlspecialchars(strtolower($activity["title"] . " " . $activity["description"]), ENT_QUOTES, "UTF-8") ?>"
                data-activity-user="<?= (int) $activity["user_id"] ?>"
                data-activity-file="<?= !empty($activity["file_path"]) ? "with" : "without" ?>"
            >

                <h2>

                    📌

                    <?= htmlspecialchars(
                        $activity["title"]
                    ) ?>

                </h2>


                <p>

                    <strong>
                        👤 Kullanıcı:
                    </strong>

                    <?= htmlspecialchars(
                        $activity["user_name"]
                    ) ?>

                </p>


                <div class="work">

                    <?= nl2br(
                        htmlspecialchars(
                            $activity["description"]
                        )
                    ) ?>

                </div>


                <div class="activity-file">

                    <strong>
                        📎 Dosya Eki
                    </strong>


                    <?php if (
                        !empty(
                            $activity["file_path"]
                        )
                    ): ?>


                        <div
                            class="formatted-file-name"
                        >

                            📄

                            <?= htmlspecialchars(
                                $display_file_name
                            ) ?>

                        </div>


                        <div class="file-meta">

                            Yüklenen dosya:

                            <?= htmlspecialchars(
                                basename(
                                    $activity["file_path"]
                                )
                            ) ?>

                        </div>


                        <br>


                        <a
                            href="dosya_goruntule.php?id=<?= (int) $activity["id"] ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="edit-button"
                        >

                            👁️ Dosyayı Görüntüle

                        </a>


                    <?php else: ?>

                        <p>
                            Bu çalışmada dosya eki bulunmuyor.
                        </p>

                    <?php endif; ?>

                </div>


                <div class="date">

                    📅

                    <?= htmlspecialchars(
                        $activity["created_at"]
                    ) ?>

                </div>

            </div>


        <?php endforeach; ?>


    <?php endif; ?>

</section>



<!-- ==================================================
     BİLDİRİMLER
================================================== -->

<section
    id="section-bildirimler"
    class="panel-section"
>

    <div class="section-title">

        <h1>

            🔔 Bildirimler

            <?php if ($unread_count > 0): ?>

                <span class="status bekliyor">

                    <?= $unread_count ?>

                    Yeni

                </span>

            <?php endif; ?>

        </h1>


        <p>
            Sistem bildirimleri burada görüntülenir.
        </p>

    </div>

    <div class="filter-box">
        <div class="filter-grid">
            <div class="filter-group">
                <label for="notificationSearch">🔎 Bildirim Ara</label>
                <input type="search" id="notificationSearch" placeholder="Başlık veya mesaj...">
            </div>
            <div class="filter-group">
                <label for="notificationStatusFilter">📌 Durum</label>
                <select id="notificationStatusFilter">
                    <option value="all">Tümü</option>
                    <option value="unread">Yeni</option>
                    <option value="read">Okundu</option>
                </select>
            </div>
            <button type="button" class="filter-reset" id="resetNotificationFilter">↺ Filtreleri Temizle</button>
        </div>
        <div class="filter-result-count" id="notificationFilterCount"></div>
    </div>

    <div class="filter-empty" id="notificationFilterEmpty">🔎 Filtrelere uygun bildirim bulunamadı.</div>

    <?php if (empty($notifications)): ?>

        <div class="empty">

            <h2>
                🔔 Henüz bildiriminiz yok.
            </h2>

            <p>
                Yeni bildirimler burada görünecek.
            </p>

        </div>

    <?php else: ?>


        <?php foreach ($notifications as $notification): ?>
            <div
                class="box filter-item notification-filter-item"
                data-notification-text="<?= htmlspecialchars(strtolower($notification["title"] . " " . $notification["message"]), ENT_QUOTES, "UTF-8") ?>"
                data-notification-status="<?= (int) $notification["is_read"] === 0 ? "unread" : "read" ?>"
            >
                <div class="notification-header">
                    <h2>
                        <?= htmlspecialchars(
                            $notification["title"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>
                    </h2>

                    <?php if ((int) $notification["is_read"] === 0): ?>
                        <span class="status incelemede">Yeni</span>
                    <?php endif; ?>
                </div>

                <div class="notification-message">
                    <?= nl2br(
                        htmlspecialchars(
                            $notification["message"],
                            ENT_QUOTES,
                            "UTF-8"
                        )
                    ) ?>
                </div>

                <div class="work-date">
                    📅
                    <?= htmlspecialchars(
                        $notification["created_at"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </div>

                <div class="notification-card-actions">
                    <form method="POST">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, "UTF-8") ?>"
                        >
                        <input
                            type="hidden"
                            name="notification_id"
                            value="<?= (int) $notification["id"] ?>"
                        >

                        <?php if ((int) $notification["is_read"] === 0): ?>
                            <button
                                type="submit"
                                name="notification_action"
                                value="mark_read"
                                class="notification-action-button notification-read-action"
                            >
                                ✓ Okundu Yap
                            </button>
                        <?php else: ?>
                            <button
                                type="submit"
                                name="notification_action"
                                value="mark_unread"
                                class="notification-action-button notification-unread-action"
                            >
                                ↺ Okunmadı Yap
                            </button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>


    <?php endif; ?>

</section>


</div>

</div>



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
     JAVASCRIPT
================================================== -->

<script>

document.addEventListener(
    "DOMContentLoaded",
    function () {

        const menuLinks =
            document.querySelectorAll(
                ".menu-link"
            );

        const sections =
            document.querySelectorAll(
                ".panel-section"
            );


        function showSection(sectionName) {

            sections.forEach(
                function (section) {

                    section.classList.remove(
                        "active-section"
                    );

                }
            );


            const selectedSection =
                document.getElementById(
                    "section-" +
                    sectionName
                );


            if (selectedSection) {

                selectedSection.classList.add(
                    "active-section"
                );

            }


            menuLinks.forEach(
                function (link) {

                    link.classList.remove(
                        "active"
                    );

                }
            );


            const activeLink =
                document.querySelector(
                    '.menu-link[data-section="' +
                    sectionName +
                    '"]'
                );


            if (activeLink) {

                activeLink.classList.add(
                    "active"
                );

            }


            window.scrollTo({
                top: 0,
                behavior: "smooth"
            });


            history.replaceState(
                null,
                "",
                "#" + sectionName
            );

        }


        menuLinks.forEach(
            function (link) {

                link.addEventListener(
                    "click",
                    function (event) {

                        const sectionName =
                            this.dataset.section;

                        // data-section olmayan arşiv/rapor gibi
                        // sayfa bağlantıları normal şekilde açılsın.
                        if (!sectionName) {
                            return;
                        }

                        event.preventDefault();

                        showSection(
                            sectionName
                        );

                    }
                );

            }
        );


        const hash =
            window.location.hash.replace(
                "#",
                ""
            );

        const initialSection =
            document.body.dataset.initialSection || "dashboard";

        const requestedSection = hash || initialSection;
        const sectionExists =
            document.getElementById(
                "section-" + requestedSection
            );

        if (sectionExists) {
            showSection(requestedSection);
        }



        // ==================================================
        // GÖREV DÜZENLEME
        // ==================================================

        const editButtons =
            document.querySelectorAll(
                ".edit-task-button"
            );


        editButtons.forEach(
            function (button) {

                button.addEventListener(
                    "click",
                    function () {

                        const taskId =
                            this.dataset.taskId;

                        const box =
                            document.getElementById(
                                "edit-task-" +
                                taskId
                            );


                        if (box) {

                            box.classList.toggle(
                                "show"
                            );


                            if (
                                box.classList.contains(
                                    "show"
                                )
                            ) {

                                box.scrollIntoView({
                                    behavior:"smooth",
                                    block:"center"
                                });

                            }

                        }

                    }
                );

            }
        );



        // ==================================================
        // GÖREV DÜZENLEME İPTAL
        // ==================================================

        const cancelButtons =
            document.querySelectorAll(
                ".cancel-edit"
            );


        cancelButtons.forEach(
            function (button) {

                button.addEventListener(
                    "click",
                    function () {

                        const taskId =
                            this.dataset.taskId;

                        const box =
                            document.getElementById(
                                "edit-task-" +
                                taskId
                            );


                        if (box) {

                            box.classList.remove(
                                "show"
                            );

                        }

                    }
                );

            }
        );



        // ==================================================
        // KULLANICI DÜZENLEME
        // ==================================================

        const editUserButtons =
            document.querySelectorAll(
                ".edit-user-button"
            );


        editUserButtons.forEach(
            function (button) {

                button.addEventListener(
                    "click",
                    function () {

                        const userId =
                            this.dataset.userId;

                        const box =
                            document.getElementById(
                                "edit-user-" +
                                userId
                            );


                        if (box) {

                            box.classList.toggle(
                                "show"
                            );


                            if (
                                box.classList.contains(
                                    "show"
                                )
                            ) {

                                box.scrollIntoView({
                                    behavior:"smooth",
                                    block:"center"
                                });

                            }

                        }

                    }
                );

            }
        );



        // ==================================================
        // KULLANICI DÜZENLEME İPTAL
        // ==================================================

        const cancelUserButtons =
            document.querySelectorAll(
                ".cancel-user-edit"
            );


        cancelUserButtons.forEach(
            function (button) {

                button.addEventListener(
                    "click",
                    function () {

                        const userId =
                            this.dataset.userId;

                        const box =
                            document.getElementById(
                                "edit-user-" +
                                userId
                            );


                        if (box) {

                            box.classList.remove(
                                "show"
                            );

                        }

                    }
                );

            }
        );


        // ==================================================
        // KULLANICI SİLME ONAYI
        // ==================================================

        const deleteUserForms =
            document.querySelectorAll(
                ".delete-user-form"
            );


        deleteUserForms.forEach(
            function (form) {

                form.addEventListener(
                    "submit",
                    function (event) {

                        const userName =
                            this.dataset.userName || "";

                        const confirmationMessage =
                            userName
                                ? userName +
                                    " adlı kullanıcıyı silmek istediğinize emin misiniz? " +
                                    "Bu kullanıcıya bağlı görevler ve çalışmalar da silinebilir."
                                : "Bu kullanıcıyı silmek istediğinize emin misiniz? " +
                                    "Bu kullanıcıya bağlı görevler ve çalışmalar da silinebilir.";


                        if (!window.confirm(confirmationMessage)) {

                            event.preventDefault();

                        }

                    }
                );

            }
        );

    }
);



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



// ==================================================
// FİLTRELER
// ==================================================

document.addEventListener("DOMContentLoaded", function () {

    function setupFilter(config) {
        const items = Array.from(document.querySelectorAll(config.itemSelector));
        if (!items.length) return;

        const search = config.searchId ? document.getElementById(config.searchId) : null;
        const selectIds = config.selectIds || [];
        const selects = selectIds.map(id => document.getElementById(id)).filter(Boolean);
        const reset = config.resetId ? document.getElementById(config.resetId) : null;
        const count = config.countId ? document.getElementById(config.countId) : null;
        const empty = config.emptyId ? document.getElementById(config.emptyId) : null;

        let currentPage = 1;
        const pageSize = Number(config.pageSize || 6);
        const pagination = config.paginationId ? document.getElementById(config.paginationId) : null;
        function apply(resetPage = false) {
            if (resetPage) currentPage = 1;
            const query = search ? search.value.trim().toLocaleLowerCase("tr-TR") : "";
            const matched = [];
            items.forEach(function (item) {
                let show = true;
                if (query && !String(item.dataset[config.searchData] || "").toLocaleLowerCase("tr-TR").includes(query)) show = false;
                (config.selectData || []).forEach(function (dataKey, index) {
                    const selected = selects[index] ? selects[index].value : "all";
                    if (selected !== "all" && String(item.dataset[dataKey] || "") !== selected) show = false;
                });
                if (show) matched.push(item);
                item.classList.toggle("is-hidden", !show);
            });
            const pageCount = pagination ? Math.max(1, Math.ceil(matched.length / pageSize)) : 1;
            if (currentPage > pageCount) currentPage = pageCount;
            if (pagination) {
                const pageStart = (currentPage - 1) * pageSize;
                matched.forEach(function (item, index) {
                    item.classList.toggle("is-hidden", index < pageStart || index >= pageStart + pageSize);
                });
                pagination.innerHTML = "";
                if (pageCount > 1) {
                    for (let page = 1; page <= pageCount; page++) {
                        const button = document.createElement("button");
                        button.type = "button";
                        button.className = "pagination-button" + (page === currentPage ? " is-active" : "");
                        button.textContent = String(page);
                        button.setAttribute("aria-label", "Sayfa " + page);
                        button.addEventListener("click", function () { currentPage = page; apply(false); });
                        pagination.appendChild(button);
                    }
                }
            }
            if (count) count.textContent = matched.length + " sonuç gösteriliyor";
            if (empty) empty.style.display = matched.length === 0 ? "block" : "none";
        }
        if (search) search.addEventListener("input", () => apply(true));
        selects.forEach(select => select.addEventListener("change", () => apply(true)));
        if (reset) {
            reset.addEventListener("click", function () {
                if (search) search.value = "";
                selects.forEach(select => select.value = "all");
                apply(true);
            });
        }

        apply();
    }

    setupFilter({
        itemSelector: ".user-filter-item",
        searchId: "userSearch",
        selectIds: ["userRoleFilter"],
        searchData: "userName",
        selectData: ["userRole"],
        resetId: "resetUserFilter",
        countId: "userFilterCount"
    });

    setupFilter({
        itemSelector: ".task-filter-item",
        searchId: "taskSearch",
        selectIds: ["taskUserFilter", "taskStatusFilter", "taskPriorityFilter"],
        searchData: "taskTitle",
        selectData: ["taskUser", "taskStatus", "taskPriority"],
        resetId: "resetTaskFilter",
        countId: "taskFilterCount",
        emptyId: "taskFilterEmpty",
        paginationId: "taskPagination",
        pageSize: 6
    });

    setupFilter({
        itemSelector: ".submission-filter-item",
        searchId: "submissionSearch",
        selectIds: ["submissionUserFilter"],
        searchData: "submissionTitle",
        selectData: ["submissionUser"],
        resetId: "resetSubmissionFilter",
        countId: "submissionFilterCount",
        emptyId: "submissionFilterEmpty"
    });

    setupFilter({
        itemSelector: ".activity-filter-item",
        searchId: "activitySearch",
        selectIds: ["activityUserFilter", "activityFileFilter"],
        searchData: "activityTitle",
        selectData: ["activityUser", "activityFile"],
        resetId: "resetActivityFilter",
        countId: "activityFilterCount",
        emptyId: "activityFilterEmpty"
    });

    setupFilter({
        itemSelector: ".notification-filter-item",
        searchId: "notificationSearch",
        selectIds: ["notificationStatusFilter"],
        searchData: "notificationText",
        selectData: ["notificationStatus"],
        resetId: "resetNotificationFilter",
        countId: "notificationFilterCount",
        emptyId: "notificationFilterEmpty"
    });
});

</script>


</body>

</html>