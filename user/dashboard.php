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
        // 1. KULLANICI ÇALIŞMASI EKLE
        // ====================================================

        if (isset($_POST["add_activity"])) {

            $title =
                trim(
                    $_POST["activity_title"] ?? ""
                );

            $description =
                trim(
                    $_POST["activity_description"] ?? ""
                );


            if ($title === "") {

                $error =
                    "Çalışma başlığı boş bırakılamaz.";

            } elseif ($description === "") {

                $error =
                    "Çalışma açıklaması boş bırakılamaz.";

            } else {

                try {

                    $file_path = null;


                    // ====================================================
                    // DOSYA YÜKLEME
                    // ====================================================

                    if (
                        isset($_FILES["activity_file"]) &&
                        $_FILES["activity_file"]["error"] !== UPLOAD_ERR_NO_FILE
                    ) {

                        if (
                            $_FILES["activity_file"]["error"] !== UPLOAD_ERR_OK
                        ) {

                            throw new Exception(
                                "Dosya yüklenirken bir hata oluştu."
                            );

                        }


                        $max_size =
                            10 * 1024 * 1024;


                        if (
                            $_FILES["activity_file"]["size"] > $max_size
                        ) {

                            throw new Exception(
                                "Dosya boyutu en fazla 10 MB olabilir."
                            );

                        }


                        $original_name =
                            $_FILES["activity_file"]["name"];


                        $extension =
                            strtolower(
                                pathinfo(
                                    $original_name,
                                    PATHINFO_EXTENSION
                                )
                            );


                        $allowed_extensions = [

                            "jpg",
                            "jpeg",
                            "png",
                            "gif",
                            "webp",

                            "pdf",

                            "doc",
                            "docx",

                            "xls",
                            "xlsx",

                            "ppt",
                            "pptx",

                            "txt",

                            "zip",
                            "rar"

                        ];


                        if (
                            !in_array(
                                $extension,
                                $allowed_extensions,
                                true
                            )
                        ) {

                            throw new Exception(
                                "Bu dosya türüne izin verilmiyor."
                            );

                        }


                        $upload_dir =
                            "../uploads/activities/";


                        if (!is_dir($upload_dir)) {

                            if (
                                !mkdir(
                                    $upload_dir,
                                    0775,
                                    true
                                )
                            ) {

                                throw new Exception(
                                    "Dosya yükleme klasörü oluşturulamadı."
                                );

                            }

                        }


                        $safe_name =
                            bin2hex(
                                random_bytes(16)
                            )
                            . "_"
                            . time()
                            . "."
                            . $extension;


                        $target_file =
                            $upload_dir . $safe_name;


                        if (
                            !move_uploaded_file(
                                $_FILES["activity_file"]["tmp_name"],
                                $target_file
                            )
                        ) {

                            throw new Exception(
                                "Dosya sunucuya yüklenemedi."
                            );

                        }


                        $file_path =
                            "uploads/activities/"
                            . $safe_name;

                    }


                    // ====================================================
                    // AKTİVİTEYİ KAYDET
                    // ====================================================

                    $activity_stmt =
                        $db->prepare("
                            INSERT INTO user_activities
                            (
                                user_id,
                                title,
                                description,
                                file_path,
                                created_at
                            )
                            VALUES
                            (
                                ?,
                                ?,
                                ?,
                                ?,
                                CURRENT_TIMESTAMP
                            )
                        ");


                    $activity_stmt->execute([

                        $user_id,
                        $title,
                        $description,
                        $file_path

                    ]);


                    // ====================================================
                    // ADMINLERE BİLDİRİM
                    // ====================================================

                    try {

                        $admin_stmt =
                            $db->query("
                                SELECT id
                                FROM users
                                WHERE role = 'admin'
                            ");


                        $admins =
                            $admin_stmt->fetchAll(
                                PDO::FETCH_COLUMN
                            );


                        if (!empty($admins)) {

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
                                    VALUES
                                    (
                                        ?,
                                        ?,
                                        ?,
                                        0,
                                        CURRENT_TIMESTAMP
                                    )
                                ");


                            foreach ($admins as $admin_id) {

                                $notification_stmt->execute([

                                    $admin_id,

                                    "Yeni kullanıcı çalışması",

                                    $user_name
                                    . " adlı kullanıcı yeni bir çalışma ekledi: "
                                    . $title

                                ]);

                            }

                        }

                    } catch (PDOException $e) {

                        // Çalışma kaydı korunur.

                    }


                    $message =
                        "Çalışmanız başarıyla eklendi.";


                } catch (Exception $e) {

                    $error =
                        $e->getMessage();


                } catch (PDOException $e) {

                    $error =
                        "Çalışma eklenirken bir hata oluştu.";

                }

            }

        }


        // ====================================================
        // 2. GÜNLÜK GÖREV GÖNDERİMİ
        // ====================================================

        if (isset($_POST["submit_daily_task"])) {

            $task_id =
                (int) ($_POST["task_id"] ?? 0);

            $content =
                trim(
                    $_POST["submission_content"] ?? ""
                );


            if ($task_id <= 0) {

                $error =
                    "Geçersiz görev.";

            } elseif ($content === "") {

                $error =
                    "Bugünkü çalışmanızı yazmalısınız.";

            } else {

                try {


                    // ====================================================
                    // GÖREVİN GERÇEKTEN BU KULLICIYA AİT OLDUĞUNU KONTROL ET
                    // ====================================================

                    $task_check =
                        $db->prepare("
                            SELECT
                                id,
                                title,
                                status
                            FROM tasks
                            WHERE id = ?
                              AND assigned_to = ?
                            LIMIT 1
                        ");


                    $task_check->execute([

                        $task_id,
                        $user_id

                    ]);


                    $assigned_task =
                        $task_check->fetch(
                            PDO::FETCH_ASSOC
                        );


                    if (!$assigned_task) {

                        throw new Exception(
                            "Bu göreve gönderim yapma yetkiniz yok."
                        );

                    }


                    if ($assigned_task["status"] === "onaylandı") {

                        throw new Exception(
                            "Onaylanmış bir göreve yeni gönderim yapılamaz."
                        );

                    }


                    // ====================================================
                    // BUGÜN DAHA ÖNCE GÖNDERİLMİŞ Mİ?
                    // ====================================================

                    $today_start =
                        date("Y-m-d 00:00:00");

                    $tomorrow_start =
                        date(
                            "Y-m-d 00:00:00",
                            strtotime("+1 day")
                        );


                    $daily_check =
                        $db->prepare("
                            SELECT id
                            FROM task_submissions
                            WHERE task_id = ?
                              AND user_id = ?
                              AND submitted_at >= ?
                              AND submitted_at < ?
                            LIMIT 1
                        ");


                    $daily_check->execute([

                        $task_id,
                        $user_id,
                        $today_start,
                        $tomorrow_start

                    ]);


                    if ($daily_check->fetch()) {

                        throw new Exception(
                            "Bu görev için bugün zaten bir gönderim yaptınız."
                        );

                    }


                    // ====================================================
                    // DOSYA
                    // ====================================================

                    $submission_file_name = null;
                    $submission_file_path = null;


                    if (
                        isset($_FILES["submission_file"]) &&
                        $_FILES["submission_file"]["error"] !== UPLOAD_ERR_NO_FILE
                    ) {


                        if (
                            $_FILES["submission_file"]["error"] !== UPLOAD_ERR_OK
                        ) {

                            throw new Exception(
                                "Gönderim dosyası yüklenirken hata oluştu."
                            );

                        }


                        $max_size =
                            10 * 1024 * 1024;


                        if (
                            $_FILES["submission_file"]["size"] > $max_size
                        ) {

                            throw new Exception(
                                "Dosya boyutu en fazla 10 MB olabilir."
                            );

                        }


                        $original_name =
                            $_FILES["submission_file"]["name"];


                        $extension =
                            strtolower(
                                pathinfo(
                                    $original_name,
                                    PATHINFO_EXTENSION
                                )
                            );


                        $allowed_extensions = [

                            "jpg",
                            "jpeg",
                            "png",
                            "gif",
                            "webp",

                            "pdf",

                            "doc",
                            "docx",

                            "xls",
                            "xlsx",

                            "ppt",
                            "pptx",

                            "txt",

                            "zip",
                            "rar"

                        ];


                        if (
                            !in_array(
                                $extension,
                                $allowed_extensions,
                                true
                            )
                        ) {

                            throw new Exception(
                                "Bu dosya türüne izin verilmiyor."
                            );

                        }


                        $upload_dir =
                            "../uploads/task_submissions/";


                        if (!is_dir($upload_dir)) {

                            if (
                                !mkdir(
                                    $upload_dir,
                                    0775,
                                    true
                                )
                            ) {

                                throw new Exception(
                                    "Gönderim dosyası klasörü oluşturulamadı."
                                );

                            }

                        }


                        $safe_name =
                            bin2hex(
                                random_bytes(16)
                            )
                            . "_"
                            . time()
                            . "."
                            . $extension;


                        $target_file =
                            $upload_dir . $safe_name;


                        if (
                            !move_uploaded_file(
                                $_FILES["submission_file"]["tmp_name"],
                                $target_file
                            )
                        ) {

                            throw new Exception(
                                "Gönderim dosyası sunucuya yüklenemedi."
                            );

                        }


                        $submission_file_name =
                            $original_name;

                        $submission_file_path =
                            "uploads/task_submissions/"
                            . $safe_name;

                    }


                    // ====================================================
                    // GÖNDERİMİ KAYDET
                    // ====================================================

                    $submission_stmt =
                        $db->prepare("
                            INSERT INTO task_submissions
                            (
                                task_id,
                                user_id,
                                content,
                                submitted_at,
                                created_at,
                                status,
                                file_name,
                                file_path
                            )
                            VALUES
                            (
                                ?,
                                ?,
                                ?,
                                CURRENT_TIMESTAMP,
                                CURRENT_TIMESTAMP,
                                ?,
                                ?,
                                ?
                            )
                        ");


                    $submission_stmt->execute([

                        $task_id,
                        $user_id,
                        $content,
                        "incelemede",
                        $submission_file_name,
                        $submission_file_path

                    ]);


                    // Gönderim alındığında görev de admin incelemesine alınır.
                    $task_status_stmt = $db->prepare("
                        UPDATE tasks
                        SET status = ?
                        WHERE id = ?
                          AND assigned_to = ?
                          AND status IN ('bekliyor', 'incelemede', 'revizyon')
                    ");

                    $task_status_stmt->execute([
                        "incelemede",
                        $task_id,
                        $user_id
                    ]);


                    // ====================================================
                    // GÜNLÜK GÖREV KAYDI
                    // ====================================================

                    try {

                        $daily_task_check =
                            $db->prepare("
                                SELECT id
                                FROM task_daily_tasks
                                WHERE task_id = ?
                                  AND task_date = ?
                                LIMIT 1
                            ");


                        $daily_task_check->execute([

                            $task_id,
                            date("Y-m-d")

                        ]);


                        if (!$daily_task_check->fetch()) {

                            $daily_task_insert =
                                $db->prepare("
                                    INSERT INTO task_daily_tasks
                                    (
                                        task_id,
                                        task_date,
                                        title,
                                        status,
                                        created_at
                                    )
                                    VALUES
                                    (
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        CURRENT_TIMESTAMP
                                    )
                                ");


                            $daily_task_insert->execute([

                                $task_id,
                                date("Y-m-d"),
                                $assigned_task["title"],
                                "incelemede"

                            ]);

                        }

                    } catch (PDOException $e) {

                        // Ana gönderim kaydı korunur.

                    }


                    // ====================================================
                    // ADMİNLERE BİLDİRİM
                    // ====================================================

                    try {

                        $admin_stmt =
                            $db->query("
                                SELECT id
                                FROM users
                                WHERE role = 'admin'
                            ");


                        $admins =
                            $admin_stmt->fetchAll(
                                PDO::FETCH_COLUMN
                            );


                        if (!empty($admins)) {

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
                                    VALUES
                                    (
                                        ?,
                                        ?,
                                        ?,
                                        0,
                                        CURRENT_TIMESTAMP
                                    )
                                ");


                            foreach ($admins as $admin_id) {

                                $notification_stmt->execute([

                                    $admin_id,

                                    "📤 Yeni Görev Gönderimi",

                                    $user_name
                                    . " adlı kullanıcı '"
                                    . $assigned_task["title"]
                                    . "' görevi için günlük çalışma gönderdi."

                                ]);

                            }

                        }

                    } catch (PDOException $e) {

                        // Bildirim başarısız olsa bile gönderim korunur.

                    }


                    $message =
                        "Bugünkü görev çalışmanız başarıyla gönderildi.";


                } catch (Exception $e) {

                    $error =
                        $e->getMessage();

                } catch (PDOException $e) {

                    $error =
                        "Günlük görev gönderilirken bir hata oluştu.";

                }

            }

        }


        // ====================================================
        // 3. BİLDİRİMLERİ OKUNDU YAP
        // ====================================================

        if (isset($_POST["mark_notifications_read"])) {

            try {

                $read_stmt =
                    $db->prepare("
                        UPDATE notifications
                        SET is_read = 1
                        WHERE user_id = ?
                    ");


                $read_stmt->execute([
                    $user_id
                ]);


                $message =
                    "Bildirimler okundu olarak işaretlendi.";


            } catch (PDOException $e) {

                $error =
                    "Bildirimler güncellenirken hata oluştu.";

            }

        }

    }

}


// ============================================================
// KULLANICIYA ATANMIŞ GÖREVLER
// ============================================================

try {

    $stmt =
        $db->prepare("
            SELECT
                id,
                title,
                description,
                assigned_to,
                assigned_by,
                due_date,
                status,
                created_at
            FROM tasks
            WHERE assigned_to = ?
            ORDER BY
                CASE
                    WHEN status = 'bekliyor' THEN 0
                    WHEN status = 'devam ediyor' THEN 1
                    WHEN status = 'tamamlandı' THEN 2
                    ELSE 3
                END,
                due_date ASC
        ");


    $stmt->execute([
        $user_id
    ]);


    $tasks =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


} catch (PDOException $e) {

    $tasks = [];

}


// ============================================================
// GÖREVLERİN GÜNLÜK GÖNDERİMLERİ
// ============================================================

$task_submissions = [];


try {

    if (!empty($tasks)) {

        $task_ids =
            array_column(
                $tasks,
                "id"
            );

        $placeholders =
            implode(
                ",",
                array_fill(
                    0,
                    count($task_ids),
                    "?"
                )
            );


        $submission_stmt =
            $db->prepare("
                SELECT
                    id,
                    task_id,
                    user_id,
                    content,
                    submitted_at,
                    created_at,
                    status,
                    file_name,
                    file_path
                FROM task_submissions
                WHERE user_id = ?
                  AND task_id IN ($placeholders)
                ORDER BY submitted_at DESC
            ");


        $params =
            array_merge(
                [$user_id],
                $task_ids
            );


        $submission_stmt->execute(
            $params
        );


        $all_submissions =
            $submission_stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        foreach ($all_submissions as $submission) {

            $submission_task_id =
                (int) $submission["task_id"];


            if (!isset($task_submissions[$submission_task_id])) {

                $task_submissions[$submission_task_id] = [];

            }


            $task_submissions[$submission_task_id][] =
                $submission;

        }

    }

} catch (PDOException $e) {

    $task_submissions = [];

}


// ============================================================
// DEADLINE HATIRLAMALARI
// ============================================================

$deadline_reminders = [];

try {

    $today =
        new DateTimeImmutable("today");


    $notification_insert =
        $db->prepare("
            INSERT INTO notifications
            (
                user_id,
                title,
                message,
                is_read,
                created_at
            )
            VALUES
            (
                ?,
                ?,
                ?,
                0,
                CURRENT_TIMESTAMP
            )
        ");


    $notification_check =
        $db->prepare("
            SELECT COUNT(*)
            FROM notifications
            WHERE user_id = ?
              AND title = ?
              AND message = ?
        ");


    foreach ($tasks as $deadline_task) {

        $deadline_value =
            trim(
                (string) (
                    $deadline_task["due_date"] ?? ""
                )
            );


        if ($deadline_value === "") {
            continue;
        }


        try {

            $deadline_date =
                new DateTimeImmutable(
                    substr(
                        $deadline_value,
                        0,
                        10
                    )
                );

        } catch (Exception $e) {

            continue;

        }


        $status =
            strtolower(
                trim(
                    (string) (
                        $deadline_task["status"] ?? ""
                    )
                )
            );


        $completed_statuses = [

            "tamamlandı",
            "tamamlandi",
            "completed",
            "onaylandı",
            "onaylandi"

        ];


        if (
            in_array(
                $status,
                $completed_statuses,
                true
            )
        ) {

            continue;

        }


        $days_left =
            (int) $today->diff(
                $deadline_date
            )->format("%r%a");


        if ($days_left < 0) {

            $reminder_type =
                "expired";

            $reminder_title =
                "🔴 Görev Süresi Doldu";

            $reminder_message =
                "“"
                . $deadline_task["title"]
                . "” görevinin son tarihi geçmiştir.";

            $reminder_text =
                "Süresi geçti";


        } elseif ($days_left === 0) {

            $reminder_type =
                "today";

            $reminder_title =
                "🚨 Görev Son Günü";

            $reminder_message =
                "“"
                . $deadline_task["title"]
                . "” görevinin son günü bugün.";

            $reminder_text =
                "Bugün son gün";


        } elseif ($days_left === 1) {

            $reminder_type =
                "1day";

            $reminder_title =
                "⚠️ Deadline Yaklaşıyor";

            $reminder_message =
                "“"
                . $deadline_task["title"]
                . "” görevinin bitmesine 1 gün kaldı.";

            $reminder_text =
                "1 gün kaldı";


        } elseif ($days_left <= 3) {

            $reminder_type =
                $days_left . "days";

            $reminder_title =
                "⏰ Deadline Yaklaşıyor";

            $reminder_message =
                "“"
                . $deadline_task["title"]
                . "” görevinin bitmesine "
                . $days_left
                . " gün kaldı.";

            $reminder_text =
                $days_left . " gün kaldı";


        } else {

            continue;

        }


        $deadline_reminders[] = [

            "task_id" =>
                (int) $deadline_task["id"],

            "title" =>
                $deadline_task["title"],

            "deadline" =>
                $deadline_date->format("Y-m-d"),

            "days_left" =>
                $days_left,

            "type" =>
                $reminder_type,

            "text" =>
                $reminder_text

        ];


        $notification_check->execute([

            $user_id,
            $reminder_title,
            $reminder_message

        ]);


        if (
            (int) $notification_check->fetchColumn() === 0
        ) {

            $notification_insert->execute([

                $user_id,
                $reminder_title,
                $reminder_message

            ]);

        }

    }

} catch (Exception $e) {

    $deadline_reminders = [];

}


// ============================================================
// KULLANICININ KENDİ ÇALIŞMALARI
// ============================================================

try {

    $stmt =
        $db->prepare("
            SELECT
                id,
                title,
                description,
                file_path,
                created_at
            FROM user_activities
            WHERE user_id = ?
            ORDER BY created_at DESC
        ");


    $stmt->execute([
        $user_id
    ]);


    $user_activities =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


} catch (PDOException $e) {

    $user_activities = [];

}


// ============================================================
// BİLDİRİMLER
// ============================================================

try {

    $stmt =
        $db->prepare("
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
        $user_id
    ]);


    $notifications =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


} catch (PDOException $e) {

    $notifications = [];

}


// ============================================================
// OKUNMAMIŞ BİLDİRİM SAYISI
// ============================================================

try {

    $stmt =
        $db->prepare("
            SELECT COUNT(*)
            FROM notifications
            WHERE user_id = ?
              AND is_read = 0
        ");


    $stmt->execute([
        $user_id
    ]);


    $unread_count =
        (int) $stmt->fetchColumn();


} catch (PDOException $e) {

    $unread_count = 0;

}


// ============================================================
// İSTATİSTİKLER
// ============================================================

$total_tasks =
    count($tasks);

$total_activities =
    count($user_activities);

$completed_tasks = 0;
$pending_tasks = 0;
$expired_tasks = 0;


foreach ($tasks as $task) {

    $status =
        strtolower(
            trim(
                $task["status"] ?? ""
            )
        );


    if (
        in_array(
            $status,
            [
                "tamamlandı",
                "tamamlandi",
                "completed"
            ],
            true
        )
    ) {

        $completed_tasks++;

    } else {

        $pending_tasks++;

    }


    if (
        !empty($task["due_date"]) &&
        strtotime($task["due_date"]) < time() &&
        !in_array(
            $status,
            [
                "tamamlandı",
                "tamamlandi",
                "completed"
            ],
            true
        )
    ) {

        $expired_tasks++;

    }

}


// ============================================================
// BUGÜN GÖNDERİM YAPILAN GÖREVLER
// ============================================================

$submitted_today = [];


foreach ($task_submissions as $task_id => $submissions) {

    foreach ($submissions as $submission) {

        if (
            date(
                "Y-m-d",
                strtotime(
                    $submission["submitted_at"]
                )
            ) === date("Y-m-d")
        ) {

            $submitted_today[(int) $task_id] = true;
            break;

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

<title>User Dashboard - Todo App</title>

<link
    rel="stylesheet"
    href="../css/style.css?v=user-dashboard-20260818"
>




</head>


<body>


<!-- ============================================================
     SIDEBAR
============================================================ -->

<div class="sidebar">

    <h2>TODO APP</h2>


    <a
        href="#dashboard"
        class="menu-link active"
        data-section="dashboard"
    >
        📊 Dashboard
    </a>


    <a
        href="#gorevler"
        class="menu-link"
        data-section="gorevler"
    >
        📋 Görevlerim
    </a>


    <a
        href="#deadline-hatirlatmalar"
        class="menu-link"
        data-section="deadline-hatirlatmalar"
    >
        ⏰ Deadline Hatırlatmaları
    </a>


    <a
        href="#kullanici-calismalari"
        class="menu-link"
        data-section="kullanici-calismalari"
    >
        📝 Çalışmalarım
    </a>


    <a
        href="#bildirimler"
        class="menu-link"
        data-section="bildirimler"
    >

        🔔 Bildirimler

        <?php if ($unread_count > 0): ?>

            <span class="notification-count">
                <?= $unread_count ?>
            </span>

        <?php endif; ?>

    </a>


    <a
        href="../logout.php"
        class="logout"
    >
        🚪 Çıkış Yap
    </a>

</div>


<!-- ============================================================
     ANA İÇERİK
============================================================ -->

<div class="main">

<div class="container">


<!-- ============================================================
     DASHBOARD
============================================================ -->

<section
    id="section-dashboard"
    class="panel-section active-section"
>

    <div class="page-header">

        <h1>User Dashboard</h1>

        <p>

            Hoş geldin,

            <strong>
                <?= htmlspecialchars(
                    $user_name,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>
            </strong>

            👋

        </p>

    </div>


    <?php if (!empty($message)): ?>

        <div class="success">

            <?= htmlspecialchars(
                $message,
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </div>

    <?php endif; ?>


    <?php if (!empty($error)): ?>

        <div class="error">

            <?= htmlspecialchars(
                $error,
                ENT_QUOTES,
                "UTF-8"
            ) ?>

        </div>

    <?php endif; ?>


    <div class="dashboard-cards">

        <div class="box dashboard-card">

            <h3>📋 Görevlerim</h3>

            <h1>
                <?= $total_tasks ?>
            </h1>

        </div>


        <div class="box dashboard-card">

            <h3>📝 Çalışmalarım</h3>

            <h1>
                <?= $total_activities ?>
            </h1>

        </div>


        <div class="box dashboard-card">

            <h3>🔔 Yeni Bildirim</h3>

            <h1>
                <?= $unread_count ?>
            </h1>

        </div>

    </div>


    <div class="dashboard-cards">

        <div class="box dashboard-card">

            <h3>✅ Tamamlanan</h3>

            <h1>
                <?= $completed_tasks ?>
            </h1>

        </div>


        <div class="box dashboard-card">

            <h3>⏳ Bekleyen</h3>

            <h1>
                <?= $pending_tasks ?>
            </h1>

        </div>


        <div class="box dashboard-card">

            <h3>⚠️ Süresi Geçen</h3>

            <h1>
                <?= $expired_tasks ?>
            </h1>

        </div>

    </div>


    <div
        class="box dashboard-welcome-box"
    >

        <h2>👋 Hoş Geldin</h2>

        <p>

            Sana atanan görevleri
            <strong>Görevlerim</strong>
            bölümünden görüntüleyebilirsin.

        </p>

        <p>

            Her atanmış görev için
            <strong>günlük çalışma gönderimi</strong>
            yapabilir ve geçmiş gönderimlerini görebilirsin.

        </p>

    </div>

</section>


<!-- ============================================================
     DEADLINE
============================================================ -->

<section
    id="section-deadline-hatirlatmalar"
    class="panel-section"
>

    <div class="section-title">

        <h1>
            ⏰ Yaklaşan Deadline'lar
        </h1>

        <p>
            Son 3 gün içinde olan ve süresi geçmiş görevlerin.
        </p>

    </div>


    <div class="box">

        <h2>
            ⏰ Deadline Durumu
        </h2>


        <?php if (empty($deadline_reminders)): ?>

            <div class="empty">

                ✅ Şu anda yaklaşan veya süresi geçmiş görev bulunmuyor.

            </div>

        <?php else: ?>

            <?php foreach ($deadline_reminders as $reminder): ?>

                <div class="task-card">

                    <h2>

                        📌

                        <?= htmlspecialchars(
                            $reminder["title"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </h2>


                    <div class="task-meta">

                        <span>

                            📅 Son tarih:

                            <?= htmlspecialchars(
                                $reminder["deadline"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </span>


                        <span>

                            <?= htmlspecialchars(
                                $reminder["text"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </span>

                    </div>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</section>


<!-- ============================================================
     GÖREVLER
============================================================ -->

<section
    id="section-gorevler"
    class="panel-section"
>

    <div class="section-title">

        <h1>
            📋 Görevlerim
        </h1>

        <p>
            Sana atanmış görevleri görüntüleyebilir ve günlük çalışma gönderebilirsin.
        </p>

    </div>


    <!-- ========================================================
         FİLTRE
    ======================================================== -->

    <div class="box task-filter-box">

        <div class="task-filter-header">

            <h2>
                🔎 Görevleri Filtrele
            </h2>

            <button
                type="button"
                id="clearTaskFilters"
                class="filter-clear-button"
            >
                Temizle
            </button>

        </div>


        <div class="task-filters">

            <div class="filter-group">

                <label for="taskSearch">
                    🔍 Görev Ara
                </label>

                <input
                    type="text"
                    id="taskSearch"
                    placeholder="Görev adı ara..."
                >

            </div>


            <div class="filter-group">

                <label for="taskStatusFilter">
                    📊 Durum
                </label>

                <select id="taskStatusFilter">

                    <option value="all">
                        Tüm Durumlar
                    </option>

                    <option value="pending">
                        Bekliyor
                    </option>

                    <option value="progress">
                        Devam Ediyor
                    </option>

                    <option value="completed">
                        Tamamlandı
                    </option>

                    <option value="expired">
                        Süresi Geçti
                    </option>

                </select>

            </div>


            <div class="filter-group">

                <label for="taskDateFilter">
                    📅 Tarih
                </label>

                <select id="taskDateFilter">

                    <option value="all">
                        Tüm Tarihler
                    </option>

                    <option value="today">
                        Bugün
                    </option>

                    <option value="week">
                        Bu Hafta
                    </option>

                    <option value="month">
                        Bu Ay
                    </option>

                </select>

            </div>

        </div>

    </div>


    <div class="task-filter-result">

        <span id="taskResultText">

            <?= count($tasks) ?>
            görev gösteriliyor

        </span>

    </div>


    <!-- ========================================================
         GÖREV LİSTESİ
    ======================================================== -->

    <div id="taskList">


        <?php if (empty($tasks)): ?>

            <div class="empty">

                <h2>
                    🎉 Şu anda atanmış göreviniz yok.
                </h2>

                <p>
                    Yeni bir görev atandığında burada görünecek.
                </p>

            </div>


        <?php else: ?>


            <?php foreach ($tasks as $task): ?>

                <?php

                $task_status =
                    strtolower(
                        trim(
                            $task["status"] ?? ""
                        )
                    );


                if (
                    in_array(
                        $task_status,
                        [
                            "tamamlandı",
                            "tamamlandi",
                            "completed"
                        ],
                        true
                    )
                ) {

                    $status_class =
                        "completed";

                    $status_text =
                        "Tamamlandı";


                } elseif (
                    in_array(
                        $task_status,
                        [
                            "devam ediyor",
                            "devam_ediyor",
                            "in_progress"
                        ],
                        true
                    )
                ) {

                    $status_class =
                        "progress";

                    $status_text =
                        "Devam Ediyor";


                } else {

                    $status_class =
                        "pending";

                    $status_text =
                        "Bekliyor";

                }


                $is_expired =
                    !empty($task["due_date"]) &&
                    strtotime($task["due_date"]) < time() &&
                    $status_class !== "completed";


                if ($is_expired) {

                    $display_status_class =
                        "expired";

                    $display_status_text =
                        "Süresi Geçti";

                } else {

                    $display_status_class =
                        $status_class;

                    $display_status_text =
                        $status_text;

                }


                $task_timestamp =
                    !empty($task["due_date"])
                        ? strtotime($task["due_date"])
                        : 0;


                $current_task_id =
                    (int) $task["id"];


                $has_submitted_today =
                    isset(
                        $submitted_today[
                            $current_task_id
                        ]
                    );

                ?>


                <div
                    class="task-card filterable-task"
                    data-title="<?= htmlspecialchars(
                        strtolower(
                            $task["title"]
                        ),
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                    data-status="<?= $display_status_class ?>"
                    data-date="<?= $task_timestamp ?>"
                >


                    <div class="task-card-top">

                        <h2>

                            📌

                            <?= htmlspecialchars(
                                $task["title"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </h2>


                        <span
                            class="status <?= $display_status_class ?>"
                        >

                            <?= $display_status_text ?>

                        </span>

                    </div>


                    <?php if (!empty($task["description"])): ?>

                        <div class="task-description">

                            <?= nl2br(
                                htmlspecialchars(
                                    $task["description"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                )
                            ) ?>

                        </div>

                    <?php endif; ?>


                    <div class="task-meta">

                        <span>

                            📊 Durum:

                            <?= htmlspecialchars(
                                $display_status_text,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </span>


                        <?php if (!empty($task["due_date"])): ?>

                            <span>

                                📅 Son Tarih:

                                <?= htmlspecialchars(
                                    $task["due_date"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </span>

                        <?php endif; ?>


                        <span>

                            🕐 Oluşturulma:

                            <?= htmlspecialchars(
                                $task["created_at"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </span>

                    </div>


                    <!-- ====================================================
                         GÜNLÜK GÖNDERİM
                    ==================================================== -->

                    <div class="daily-submission-box">

                        <h3>
                            📤 Bugünkü Görev Çalışması
                        </h3>

                        <p>
                            Bu görev için bugün yaptığınız çalışmayı gönderin.
                        </p>


                        <?php if ($has_submitted_today): ?>

                            <div class="daily-submitted">

                                ✅
                                <strong>
                                    Bugünkü çalışmanızı zaten gönderdiniz.
                                </strong>

                            </div>


                        <?php else: ?>


                            <?php if ($display_status_class !== "completed"): ?>

                                <form
                                    method="POST"
                                    enctype="multipart/form-data"
                                    class="daily-submission-form"
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
                                        name="task_id"
                                        value="<?= $current_task_id ?>"
                                    >


                                    <textarea
                                        name="submission_content"
                                        placeholder="Bugün bu görev kapsamında neler yaptığınızı yazın..."
                                        required
                                    ></textarea>


                                    <input
                                        type="file"
                                        name="submission_file"
                                    >


                                    <small class="upload-help">
                                        Maksimum 10 MB.
                                        JPG, PNG, PDF, DOCX, XLSX,
                                        PPTX, ZIP vb. desteklenmektedir.
                                    </small>


                                    <button
                                        type="submit"
                                        name="submit_daily_task"
                                        class="daily-submit-button"
                                    >

                                        📤 Bugünkü Çalışmayı Gönder

                                    </button>

                                </form>

                            <?php else: ?>

                                <div class="daily-submitted">

                                    ✅
                                    Bu görev tamamlandığı için yeni günlük gönderim yapılamaz.

                                </div>

                            <?php endif; ?>


                        <?php endif; ?>


                        <!-- ====================================================
                             GÖNDERİM GEÇMİŞİ
                        ==================================================== -->

                        <?php

                        $current_submissions =
                            $task_submissions[
                                $current_task_id
                            ] ?? [];

                        ?>


                        <?php if (!empty($current_submissions)): ?>

                            <div class="submission-history">

                                <div class="submission-history-title">

                                    📅
                                    <strong>
                                        Geçmiş Gönderimler
                                    </strong>

                                </div>


                                <?php foreach ($current_submissions as $submission): ?>

                                    <div class="submission-item">

                                        <div class="submission-item-header">

                                            <strong>

                                                📤
                                                <?= htmlspecialchars(
                                                    $submission["status"] ?? "Gönderildi",
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>

                                            </strong>


                                            <span class="submission-date">

                                                <?= htmlspecialchars(
                                                    $submission["submitted_at"],
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>

                                            </span>

                                        </div>


                                        <div class="submission-content">

                                            <?= nl2br(
                                                htmlspecialchars(
                                                    $submission["content"],
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                )
                                            ) ?>

                                        </div>


                                        <?php if (!empty($submission["file_path"])): ?>

                                            <a
                                                href="../<?= htmlspecialchars(
                                                    $submission["file_path"],
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>"
                                                target="_blank"
                                                class="submission-file"
                                            >

                                                📎

                                                <?= htmlspecialchars(
                                                    $submission["file_name"]
                                                        ?: basename(
                                                            $submission["file_path"]
                                                        ),
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>

                                            </a>

                                        <?php endif; ?>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php endif; ?>


                    </div>


                </div>


            <?php endforeach; ?>


        <?php endif; ?>


    </div>


    <div
        id="noTaskResult"
        class="empty no-task-result"
    >

        <h2>
            🔍 Görev bulunamadı
        </h2>

        <p>
            Seçtiğiniz filtrelere uygun görev bulunmuyor.
        </p>

    </div>


</section>


<!-- ============================================================
     KULLANICI ÇALIŞMALARI
============================================================ -->

<section
    id="section-kullanici-calismalari"
    class="panel-section"
>

    <div class="section-title">

        <h1>
            📝 Çalışmalarım
        </h1>

        <p>
            Görev dışında eklediğiniz çalışmalar.
        </p>

    </div>


    <div class="box">

        <h2>
            ➕ Yeni Çalışma Ekle
        </h2>

        <p class="muted-text">

            Çalışmanızı ve isterseniz dosyanızı
            buradan ekleyebilirsiniz.

        </p>


        <div class="form-box">

            <form
                method="POST"
                enctype="multipart/form-data"
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


                <div class="form-group">

                    <label>
                        Çalışma Başlığı
                    </label>

                    <input
                        type="text"
                        name="activity_title"
                        placeholder="Örn: PHP Todo App"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>
                        Açıklama
                    </label>

                    <textarea
                        name="activity_description"
                        placeholder="Çalışmanız hakkında bilgi yazın..."
                        required
                    ></textarea>

                </div>


                <div class="form-group">

                    <label>
                        Dosya Eki
                    </label>

                    <input
                        type="file"
                        name="activity_file"
                    >

                    <small class="muted-text">

                        Maksimum 10 MB.
                        JPG, PNG, PDF, DOCX, XLSX,
                        PPTX, ZIP vb. desteklenmektedir.

                    </small>

                </div>


                <button
                    type="submit"
                    name="add_activity"
                    class="primary-button"
                >

                    📤 Çalışmayı Kaydet

                </button>


            </form>

        </div>

    </div>


    <div class="activity-history">


        <?php if (empty($user_activities)): ?>


            <div class="empty">

                <h2>
                    Henüz çalışma eklemediniz.
                </h2>

                <p>
                    İlk çalışmanızı yukarıdaki formdan ekleyebilirsiniz.
                </p>

            </div>


        <?php else: ?>


            <?php foreach ($user_activities as $activity): ?>


                <?php

                $original_file =
                    $activity["file_path"] ?? "";

                $file_extension = "";


                if (!empty($original_file)) {

                    $file_extension =
                        strtolower(
                            pathinfo(
                                $original_file,
                                PATHINFO_EXTENSION
                            )
                        );

                }


                $formatted_user_name =
                    trim(
                        preg_replace(
                            '/\s+/',
                            ' ',
                            $user_name
                        )
                    );


                $formatted_user_name =
                    preg_replace(
                        '/[\\\\\/:*?"<>|]/',
                        '-',
                        $formatted_user_name
                    );


                $formatted_task =
                    trim(
                        preg_replace(
                            '/\s+/',
                            ' ',
                            $activity["title"]
                        )
                    );


                $formatted_task =
                    preg_replace(
                        '/[\\\\\/:*?"<>|]/',
                        '-',
                        $formatted_task
                    );


                $timestamp =
                    strtotime(
                        $activity["created_at"]
                    );


                $formatted_date =
                    $timestamp
                        ? date(
                            "d.m.Y_H-i-s",
                            $timestamp
                        )
                        : date(
                            "d.m.Y_H-i-s"
                        );


                if (!empty($file_extension)) {

                    $display_file_name =
                        $formatted_task
                        . "_"
                        . $formatted_user_name
                        . "_"
                        . $formatted_date
                        . "."
                        . $file_extension;

                } else {

                    $display_file_name =
                        $formatted_task
                        . "_"
                        . $formatted_user_name
                        . "_"
                        . $formatted_date;

                }

                ?>


                <div class="box work-card">


                    <h2>

                        📌

                        <?= htmlspecialchars(
                            $activity["title"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </h2>


                    <div class="work-content">

                        <?= nl2br(
                            htmlspecialchars(
                                $activity["description"],
                                ENT_QUOTES,
                                "UTF-8"
                            )
                        ) ?>

                    </div>


                    <?php if (!empty($activity["file_path"])): ?>

                        <div class="activity-file">

                            <strong>
                                📎 Dosya Eki
                            </strong>


                            <div class="formatted-file-name">

                                📄

                                <?= htmlspecialchars(
                                    $display_file_name,
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </div>


                            <div class="file-meta">

                                Sunucudaki dosya:

                                <?= htmlspecialchars(
                                    basename(
                                        $activity["file_path"]
                                    ),
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>

                            </div>


                            <a
                                href="dosya_goruntule.php?id=<?= (int) $activity["id"] ?>"
                                class="file-button"
                            >

                                👁️ Dosyayı Görüntüle

                            </a>

                        </div>


                    <?php else: ?>

                        <div class="activity-file">

                            <strong>
                                📎 Dosya Eki
                            </strong>

                            <p class="muted-text">

                                Bu çalışmada dosya eki bulunmuyor.

                            </p>

                        </div>

                    <?php endif; ?>


                    <div class="work-date">

                        📅

                        <?= htmlspecialchars(
                            $activity["created_at"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </div>


                </div>


            <?php endforeach; ?>


        <?php endif; ?>


    </div>


</section>


<!-- ============================================================
     BİLDİRİMLER
============================================================ -->

<section
    id="section-bildirimler"
    class="panel-section"
>

    <div class="section-title">

        <h1>

            🔔 Bildirimler

            <?php if ($unread_count > 0): ?>

                <span class="status pending">

                    <?= $unread_count ?>
                    Yeni

                </span>

            <?php endif; ?>

        </h1>


        <p>
            Size gönderilen sistem bildirimleri.
        </p>

    </div>


    <?php if ($unread_count > 0): ?>


        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $csrf_token,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>"
            >


            <button
                type="submit"
                name="mark_notifications_read"
                class="primary-button notifications-read-button"
            >

                ✓ Tümünü Okundu İşaretle

            </button>

        </form>


    <?php endif; ?>


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
                class="notification-card
                <?= (int) $notification["is_read"] === 0
                    ? "unread"
                    : "" ?>"
            >


                <div class="notification-header">

                    <h2>

                        <?= htmlspecialchars(
                            $notification["title"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </h2>


                    <?php if (
                        (int) $notification["is_read"] === 0
                    ): ?>

                        <span class="status pending">
                            Yeni
                        </span>

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


            </div>


        <?php endforeach; ?>


    <?php endif; ?>


</section>


</div>

</div>


<!-- ============================================================
     TEMA
============================================================ -->

<button
    class="theme-toggle"
    id="themeToggle"
    title="Tema değiştir"
    type="button"
>
    🌙
</button>


<script>


// ============================================================
// MENÜ
// ============================================================

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
                    "section-" + sectionName
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

                        event.preventDefault();

                        showSection(
                            this.dataset.section
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


        if (hash) {

            const sectionExists =
                document.getElementById(
                    "section-" + hash
                );


            if (sectionExists) {

                showSection(hash);

            }

        }

    }
);


// ============================================================
// TEMA
// ============================================================

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


// ============================================================
// GÖREV FİLTRELEME
// ============================================================

const taskSearch =
    document.getElementById(
        "taskSearch"
    );


const taskStatusFilter =
    document.getElementById(
        "taskStatusFilter"
    );


const taskDateFilter =
    document.getElementById(
        "taskDateFilter"
    );


const clearTaskFilters =
    document.getElementById(
        "clearTaskFilters"
    );


const taskResultText =
    document.getElementById(
        "taskResultText"
    );


const noTaskResult =
    document.getElementById(
        "noTaskResult"
    );


function filterTasks() {

    const searchValue =
        taskSearch.value
            .toLocaleLowerCase("tr-TR")
            .trim();


    const statusValue =
        taskStatusFilter.value;


    const dateValue =
        taskDateFilter.value;


    const tasks =
        document.querySelectorAll(
            ".filterable-task"
        );


    let visibleCount = 0;


    const now =
        new Date();


    const todayStart =
        new Date(
            now.getFullYear(),
            now.getMonth(),
            now.getDate()
        );


    const tomorrow =
        new Date(todayStart);


    tomorrow.setDate(
        tomorrow.getDate() + 1
    );


    const weekStart =
        new Date(todayStart);


    let day =
        weekStart.getDay();


    if (day === 0) {
        day = 7;
    }


    weekStart.setDate(
        weekStart.getDate() - day + 1
    );


    const nextWeek =
        new Date(weekStart);


    nextWeek.setDate(
        nextWeek.getDate() + 7
    );


    const monthStart =
        new Date(
            now.getFullYear(),
            now.getMonth(),
            1
        );


    const nextMonth =
        new Date(
            now.getFullYear(),
            now.getMonth() + 1,
            1
        );


    tasks.forEach(
        function (task) {

            const title =
                task.dataset.title || "";


            const status =
                task.dataset.status || "";


            const timestamp =
                parseInt(
                    task.dataset.date || "0"
                );


            const matchesSearch =
                title.includes(
                    searchValue
                );


            const matchesStatus =
                statusValue === "all" ||
                status === statusValue;


            let matchesDate = true;


            if (
                dateValue !== "all"
            ) {

                if (timestamp === 0) {

                    matchesDate = false;

                } else {

                    const taskDate =
                        new Date(
                            timestamp * 1000
                        );


                    if (
                        dateValue === "today"
                    ) {

                        matchesDate =
                            taskDate >= todayStart &&
                            taskDate < tomorrow;

                    }


                    if (
                        dateValue === "week"
                    ) {

                        matchesDate =
                            taskDate >= weekStart &&
                            taskDate < nextWeek;

                    }


                    if (
                        dateValue === "month"
                    ) {

                        matchesDate =
                            taskDate >= monthStart &&
                            taskDate < nextMonth;

                    }

                }

            }


            const visible =
                matchesSearch &&
                matchesStatus &&
                matchesDate;


            if (visible) {

                task.style.display =
                    "";

                visibleCount++;

            } else {

                task.style.display =
                    "none";

            }

        }
    );


    taskResultText.textContent =
        visibleCount +
        " görev gösteriliyor";


    if (visibleCount === 0) {

        noTaskResult.style.display =
            "block";

    } else {

        noTaskResult.style.display =
            "none";

    }

}


if (taskSearch) {

    taskSearch.addEventListener(
        "input",
        filterTasks
    );

}


if (taskStatusFilter) {

    taskStatusFilter.addEventListener(
        "change",
        filterTasks
    );

}


if (taskDateFilter) {

    taskDateFilter.addEventListener(
        "change",
        filterTasks
    );

}


if (clearTaskFilters) {

    clearTaskFilters.addEventListener(
        "click",
        function () {

            taskSearch.value =
                "";

            taskStatusFilter.value =
                "all";

            taskDateFilter.value =
                "all";

            filterTasks();

        }
    );

}

</script>


</body>

</html>