<?php

session_start();

require_once "../config/database.php";


// ============================================================
// SQLITE ÇOKLU DOSYA TABLOSU
// ============================================================

try {

    $db->exec("PRAGMA foreign_keys = ON");

    $db->exec("
        CREATE TABLE IF NOT EXISTS task_submission_files (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            submission_id INTEGER NOT NULL,
            original_name TEXT NOT NULL,
            file_path TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (submission_id)
                REFERENCES task_submissions (id)
                ON DELETE CASCADE
                ON UPDATE CASCADE
        )
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS
        idx_task_submission_files_submission_id
        ON task_submission_files (submission_id)
    ");

} catch (PDOException $e) {

    // Kurulum SQL'i ayrıca çalıştırılmışsa bu blok zaten sorunsuz geçer.

}


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

$profile_image = "";
$profile_image_url = "";
$profile_stmt = $db->prepare("
    SELECT profile_image
    FROM users
    WHERE id = ?
    LIMIT 1
");
$profile_stmt->execute([$user_id]);
$profile_image = (string) ($profile_stmt->fetchColumn() ?: "");

if ($profile_image !== "") {
    $profile_root = realpath(__DIR__ . "/../uploads/profile_images");
    $profile_candidate = realpath(
        __DIR__ . "/../" . str_replace("/", DIRECTORY_SEPARATOR, $profile_image)
    );

    if (
        $profile_root !== false &&
        $profile_candidate !== false &&
        (
            $profile_candidate === $profile_root ||
            strncmp(
                $profile_candidate,
                $profile_root . DIRECTORY_SEPARATOR,
                strlen($profile_root . DIRECTORY_SEPARATOR)
            ) === 0
        ) &&
        is_file($profile_candidate)
    ) {
        $profile_image_url = "../" . ltrim($profile_image, "/\\");
    }
}

$message = "";
$error = "";


$turkey_timezone = new DateTimeZone("Europe/Istanbul");
$utc_timezone = new DateTimeZone("UTC");
$turkey_now = new DateTimeImmutable("now", $turkey_timezone);
$utc_now = $turkey_now
    ->setTimezone($utc_timezone)
    ->format("Y-m-d H:i:s");
$today_turkey = $turkey_now->format("Y-m-d");


/**
 * Veritabanında UTC tutulan tarihi Türkiye saatine çevirir.
 */
function format_turkey_datetime($value): string
{

    $value = trim((string) $value);


    if ($value === "") {
        return "-";
    }


    try {

        $utc_date = new DateTimeImmutable(
            $value,
            new DateTimeZone("UTC")
        );

        return $utc_date
            ->setTimezone(new DateTimeZone("Europe/Istanbul"))
            ->format("Y-m-d H:i:s");

    } catch (Exception $e) {

        return $value;

    }
}


function validate_uploaded_file_type(array $file): string
{

    $allowedExtensions = [
        "pdf",
        "doc",
        "docx",
        "jpg",
        "jpeg",
        "png",
        "xls",
        "xlsx"
    ];

    $allowedMimeTypes = [
        "application/pdf",
        "application/msword",
        "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        "image/jpeg",
        "image/png",
        "application/vnd.ms-excel",
        "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
    ];

    $mimeTypesByExtension = [
        "pdf" => ["application/pdf"],
        "doc" => ["application/msword"],
        "docx" => [
            "application/vnd.openxmlformats-officedocument.wordprocessingml.document"
        ],
        "jpg" => ["image/jpeg"],
        "jpeg" => ["image/jpeg"],
        "png" => ["image/png"],
        "xls" => ["application/vnd.ms-excel"],
        "xlsx" => [
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
        ]
    ];

    $errorMessage =
        "Yüklenen dosya şu dosya türlerinden biri olmalıdır: "
        . implode(", ", $allowedExtensions)
        . PHP_EOL
        . "Yüklenen dosya şu dosya türlerinden biri olmalıdır: "
        . implode(", ", $allowedMimeTypes)
        . ".";

    $originalName = (string) ($file["name"] ?? "");
    $extension = strtolower(
        pathinfo($originalName, PATHINFO_EXTENSION)
    );
    $temporaryPath = (string) ($file["tmp_name"] ?? "");

    if (
        $extension === "" ||
        !isset($mimeTypesByExtension[$extension]) ||
        $temporaryPath === "" ||
        !is_uploaded_file($temporaryPath)
    ) {
        throw new Exception($errorMessage);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    if ($finfo === false) {
        throw new Exception($errorMessage);
    }

    $detectedMimeType = finfo_file($finfo, $temporaryPath);
    finfo_close($finfo);

    if (
        $detectedMimeType === false ||
        !in_array(
            $detectedMimeType,
            $mimeTypesByExtension[$extension],
            true
        )
    ) {
        throw new Exception($errorMessage);
    }

    return $extension;
}


// ============================================================
// CSRF TOKEN
// ============================================================

if (empty($_SESSION["csrf_token"])) {

    $_SESSION["csrf_token"] =
        bin2hex(random_bytes(32));

}

$csrf_token =
    $_SESSION["csrf_token"];

$active_section = "dashboard";


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
                    $_FILES["profile_image"]["error"] !== UPLOAD_ERR_OK
                ) {
                    throw new Exception("Lütfen geçerli bir profil fotoğrafı seçin.");
                }

                $profile_file = $_FILES["profile_image"];
                $profile_extension = strtolower(
                    pathinfo($profile_file["name"] ?? "", PATHINFO_EXTENSION)
                );
                $profile_mimes = [
                    "jpg" => "image/jpeg",
                    "jpeg" => "image/jpeg",
                    "png" => "image/png"
                ];

                if (
                    !isset($profile_mimes[$profile_extension]) ||
                    (int) ($profile_file["size"] ?? 0) > 5 * 1024 * 1024 ||
                    !is_uploaded_file($profile_file["tmp_name"] ?? "")
                ) {
                    throw new Exception(
                        "Profil fotoğrafı JPG, JPEG veya PNG olmalı ve en fazla 5 MB olabilir."
                    );
                }

                $profile_finfo = finfo_open(FILEINFO_MIME_TYPE);
                $profile_mime = $profile_finfo
                    ? finfo_file($profile_finfo, $profile_file["tmp_name"])
                    : false;
                if ($profile_finfo) {
                    finfo_close($profile_finfo);
                }

                if ($profile_mime !== $profile_mimes[$profile_extension]) {
                    throw new Exception(
                        "Profil fotoğrafının gerçek dosya türü JPG, JPEG veya PNG olmalıdır."
                    );
                }

                $profile_upload_dir = "../uploads/profile_images/";
                if (!is_dir($profile_upload_dir) && !mkdir($profile_upload_dir, 0775, true)) {
                    throw new Exception("Profil fotoğrafı klasörü oluşturulamadı.");
                }

                $old_profile_stmt = $db->prepare(
                    "SELECT profile_image FROM users WHERE id = ? LIMIT 1"
                );
                $old_profile_stmt->execute([$user_id]);
                $old_profile_image = (string) ($old_profile_stmt->fetchColumn() ?: "");

                $profile_filename =
                    "profile_" . $user_id . "_" . bin2hex(random_bytes(8)) . "." . $profile_extension;
                $profile_target = $profile_upload_dir . $profile_filename;

                if (!move_uploaded_file($profile_file["tmp_name"], $profile_target)) {
                    throw new Exception("Profil fotoğrafı sunucuya yüklenemedi.");
                }

                $profile_path = "uploads/profile_images/" . $profile_filename;
                $profile_update_stmt = $db->prepare(
                    "UPDATE users SET profile_image = ? WHERE id = ?"
                );
                $profile_update_stmt->execute([$profile_path, $user_id]);
                $profile_image = $profile_path;
                $profile_image_url = "../" . $profile_path;

                if ($old_profile_image !== "") {
                    $old_profile_file = realpath(
                        __DIR__ . "/../" . str_replace("/", DIRECTORY_SEPARATOR, $old_profile_image)
                    );
                    $profile_root = realpath(__DIR__ . "/../uploads/profile_images");
                    if (
                        $old_profile_file !== false &&
                        $profile_root !== false &&
                        strncmp(
                            $old_profile_file,
                            $profile_root . DIRECTORY_SEPARATOR,
                            strlen($profile_root . DIRECTORY_SEPARATOR)
                        ) === 0 &&
                        is_file($old_profile_file)
                    ) {
                        @unlink($old_profile_file);
                    }
                }

                $message = "Profil fotoğrafınız başarıyla güncellendi.";
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }

        // ====================================================
        // PROFİL FOTOĞRAFI SİL
        // ====================================================

        if (isset($_POST["delete_profile_image"])) {

            try {
                $delete_profile_stmt = $db->prepare(
                    "SELECT profile_image FROM users WHERE id = ? LIMIT 1"
                );
                $delete_profile_stmt->execute([$user_id]);
                $delete_profile_image = (string) ($delete_profile_stmt->fetchColumn() ?: "");

                $delete_profile_file = realpath(
                    __DIR__ . "/../" . str_replace("/", DIRECTORY_SEPARATOR, $delete_profile_image)
                );
                $profile_root = realpath(__DIR__ . "/../uploads/profile_images");
                if (
                    $delete_profile_file !== false &&
                    $profile_root !== false &&
                    strncmp(
                        $delete_profile_file,
                        $profile_root . DIRECTORY_SEPARATOR,
                        strlen($profile_root . DIRECTORY_SEPARATOR)
                    ) === 0 &&
                    is_file($delete_profile_file)
                ) {
                    @unlink($delete_profile_file);
                }

                $delete_profile_update = $db->prepare(
                    "UPDATE users SET profile_image = NULL WHERE id = ?"
                );
                $delete_profile_update->execute([$user_id]);
                $profile_image = "";
                $profile_image_url = "";
                $message = "Profil fotoğrafınız silindi.";
            } catch (Exception $e) {
                $error = "Profil fotoğrafı silinirken bir hata oluştu.";
            }
        }

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
                            validate_uploaded_file_type(
                                $_FILES["activity_file"]
                            );


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


                    // ====================================================
                    // BUGÜN DAHA ÖNCE GÖNDERİLMİŞ Mİ?
                    // ====================================================

                    $today_start = $turkey_now
                        ->setTime(0, 0, 0)
                        ->setTimezone($utc_timezone)
                        ->format("Y-m-d H:i:s");

                    $tomorrow_start = $turkey_now
                        ->modify("+1 day")
                        ->setTime(0, 0, 0)
                        ->setTimezone($utc_timezone)
                        ->format("Y-m-d H:i:s");


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
                    $uploaded_submission_files = [];
                    $submission_files_to_upload = [];
                    $max_file_size = 10 * 1024 * 1024;
                    $max_file_count = 10;


                    if (
                        isset($_FILES["submission_files"])
                        && is_array(
                            $_FILES["submission_files"]["name"] ?? null
                        )
                    ) {

                        $submission_file_count = count(
                            $_FILES["submission_files"]["name"]
                        );

                        if ($submission_file_count > $max_file_count) {

                            throw new Exception(
                                "Bir gönderimde en fazla "
                                . $max_file_count
                                . " dosya seçebilirsiniz."
                            );

                        }

                        for (
                            $file_index = 0;
                            $file_index < $submission_file_count;
                            $file_index++
                        ) {

                            $submission_files_to_upload[] = [
                                "name" => $_FILES["submission_files"]["name"][$file_index] ?? "",
                                "type" => $_FILES["submission_files"]["type"][$file_index] ?? "",
                                "tmp_name" => $_FILES["submission_files"]["tmp_name"][$file_index] ?? "",
                                "error" => $_FILES["submission_files"]["error"][$file_index] ?? UPLOAD_ERR_NO_FILE,
                                "size" => $_FILES["submission_files"]["size"][$file_index] ?? 0
                            ];

                        }

                    } elseif (
                        isset($_FILES["submission_file"])
                        && $_FILES["submission_file"]["error"] !== UPLOAD_ERR_NO_FILE
                    ) {

                        // Eski tek dosyalı form gönderimleri için geriye dönük uyumluluk.
                        $submission_files_to_upload[] = $_FILES["submission_file"];

                    }


                    if (!empty($submission_files_to_upload)) {

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


                        foreach (
                            $submission_files_to_upload as $submission_file
                        ) {

                            if (
                                ($submission_file["error"] ?? UPLOAD_ERR_NO_FILE)
                                === UPLOAD_ERR_NO_FILE
                            ) {

                                continue;

                            }

                            if (
                                ($submission_file["error"] ?? UPLOAD_ERR_NO_FILE)
                                !== UPLOAD_ERR_OK
                            ) {

                                throw new Exception(
                                    "Gönderim dosyası yüklenirken hata oluştu."
                                );

                            }

                            if (
                                (int) ($submission_file["size"] ?? 0)
                                > $max_file_size
                            ) {

                                throw new Exception(
                                    "Her dosya en fazla 10 MB olabilir."
                                );

                            }

                            $original_name = (string) (
                                $submission_file["name"] ?? ""
                            );

                            $extension =
                                validate_uploaded_file_type(
                                    $submission_file
                                );

                            $safe_name =
                                bin2hex(random_bytes(16))
                                . "_"
                                . time()
                                . "."
                                . $extension;

                            $target_file =
                                $upload_dir . $safe_name;

                            if (
                                !move_uploaded_file(
                                    $submission_file["tmp_name"],
                                    $target_file
                                )
                            ) {

                                throw new Exception(
                                    "Gönderim dosyası sunucuya yüklenemedi."
                                );

                            }

                            $stored_path =
                                "uploads/task_submissions/"
                                . $safe_name;

                            $uploaded_submission_files[] = [
                                "original_name" => $original_name,
                                "file_path" => $stored_path,
                                "absolute_path" => $target_file
                            ];

                        }

                        if (!empty($uploaded_submission_files)) {

                            // Eski kolonlar geriye dönük uyumluluk için ilk eki tutar.
                            $submission_file_name =
                                $uploaded_submission_files[0]["original_name"];

                            $submission_file_path =
                                $uploaded_submission_files[0]["file_path"];

                        }

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
                                ?,
                                ?,
                                ?,
                                ?,
                                ?
                            )
                        ");


                    $submission_stmt->execute([

                        $task_id,
                        $user_id,
                        $content,
                        $utc_now,
                        $utc_now,
                        "incelemede",
                        $submission_file_name,
                        $submission_file_path

                    ]);

                    $submission_id =
                        (int) $db->lastInsertId();

                    if (
                        $submission_id > 0
                        && !empty($uploaded_submission_files)
                    ) {

                        $submission_file_stmt =
                            $db->prepare("
                                INSERT INTO task_submission_files
                                (
                                    submission_id,
                                    original_name,
                                    file_path,
                                    created_at
                                )
                                VALUES
                                (?, ?, ?, ?)
                            ");

                        foreach (
                            $uploaded_submission_files as $stored_file
                        ) {

                            $submission_file_stmt->execute([
                                $submission_id,
                                $stored_file["original_name"],
                                $stored_file["file_path"],
                                $utc_now
                            ]);

                        }

                    }


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
                                $today_turkey

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
                                        ?
                                    )
                                ");


                            $daily_task_insert->execute([

                                $task_id,
                                $today_turkey,
                                $assigned_task["title"],
                                "incelemede",
                                $utc_now

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

                    foreach (
                        $uploaded_submission_files ?? [] as $stored_file
                    ) {

                        $relative_path = str_replace(
                            "/",
                            DIRECTORY_SEPARATOR,
                            ltrim(
                                $stored_file["file_path"] ?? "",
                                "/\\"
                            )
                        );

                        $absolute_path =
                            dirname(__DIR__)
                            . DIRECTORY_SEPARATOR
                            . $relative_path;

                        if (is_file($absolute_path)) {
                            @unlink($absolute_path);
                        }

                    }

                    $error =
                        $e->getMessage();

                } catch (PDOException $e) {

                    foreach (
                        $uploaded_submission_files ?? [] as $stored_file
                    ) {

                        $relative_path = str_replace(
                            "/",
                            DIRECTORY_SEPARATOR,
                            ltrim(
                                $stored_file["file_path"] ?? "",
                                "/\\"
                            )
                        );

                        $absolute_path =
                            dirname(__DIR__)
                            . DIRECTORY_SEPARATOR
                            . $relative_path;

                        if (is_file($absolute_path)) {
                            @unlink($absolute_path);
                        }

                    }

                    $error =
                        "Günlük görev gönderilirken bir hata oluştu.";

                }

            }

        }


        // ====================================================
        // TEKİL BİLDİRİMİ OKUNDU / OKUNMADI YAP
        // ====================================================

        if (isset($_POST["notification_action"])) {

            $active_section = "bildirimler";

            $notification_action =
                (string) $_POST["notification_action"];

            $notification_id = filter_var(
                $_POST["notification_id"] ?? null,
                FILTER_VALIDATE_INT,
                [
                    "options" => [
                        "min_range" => 1
                    ]
                ]
            );

            $allowed_notification_actions = [
                "mark_read",
                "mark_unread"
            ];


            if (
                $notification_id === false
                || !in_array(
                    $notification_action,
                    $allowed_notification_actions,
                    true
                )
            ) {

                $error =
                    "Geçersiz bildirim işlemi.";

            } else {

                try {

                    $new_read_value =
                        $notification_action === "mark_read"
                        ? 1
                        : 0;

                    $notification_update =
                        $db->prepare("
                            UPDATE notifications
                            SET is_read = ?
                            WHERE id = ?
                              AND user_id = ?
                        ");


                    $notification_update->execute([
                        $new_read_value,
                        $notification_id,
                        $user_id
                    ]);


                    if ($notification_update->rowCount() > 0) {

                        $message =
                            $new_read_value === 1
                            ? "Bildirim okundu olarak işaretlendi."
                            : "Bildirim okunmadı olarak işaretlendi.";

                    } else {

                        $error =
                            "Bildirim bulunamadı veya bu işlem için yetkiniz yok.";

                    }

                } catch (PDOException $e) {

                    $error =
                        "Bildirim güncellenirken hata oluştu.";

                }

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
                tasks.id,
                tasks.title,
                tasks.description,
                tasks.assigned_to,
                tasks.assigned_by,
                tasks.due_date,
                tasks.status,
                tasks.created_at,
                assigned_admin.full_name AS assigned_by_name,
                assigned_admin.username AS assigned_by_username
            FROM tasks
            LEFT JOIN users assigned_admin
                ON tasks.assigned_by = assigned_admin.id
            WHERE tasks.assigned_to = ?
            ORDER BY
                CASE
                    WHEN tasks.status = 'bekliyor' THEN 0
                    WHEN tasks.status = 'devam ediyor' THEN 1
                    WHEN tasks.status = 'tamamlandı' THEN 2
                    ELSE 3
                END,
                tasks.due_date ASC
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

        $submission_files_by_submission = [];
        $submission_ids = [];

        foreach ($all_submissions as $submission_row) {

            $current_submission_id =
                (int) ($submission_row["id"] ?? 0);

            if ($current_submission_id > 0) {
                $submission_ids[] = $current_submission_id;
            }

        }

        $submission_ids = array_values(
            array_unique($submission_ids)
        );

        if (!empty($submission_ids)) {

            try {

                $file_placeholders = implode(
                    ",",
                    array_fill(0, count($submission_ids), "?")
                );

                $submission_files_stmt = $db->prepare(
                    "SELECT submission_id, original_name, file_path "
                    . "FROM task_submission_files "
                    . "WHERE submission_id IN ("
                    . $file_placeholders
                    . ") ORDER BY id ASC"
                );

                $submission_files_stmt->execute($submission_ids);

                foreach (
                    $submission_files_stmt->fetchAll(PDO::FETCH_ASSOC)
                    as $submission_file_row
                ) {

                    $submission_files_by_submission[
                        (int) $submission_file_row["submission_id"]
                    ][] = $submission_file_row;

                }

            } catch (PDOException $e) {

                // Yardımcı tablo kurulmamışsa eski tek ek alanı kullanılır.
                $submission_files_by_submission = [];

            }

        }

        $submission_project_root = realpath(__DIR__ . "/..");
        $submission_upload_root = realpath(
            __DIR__ . "/../uploads/task_submissions"
        );

        foreach ($all_submissions as &$submission) {

            $submission["attachments"] = [];

            $attachment_rows =
                $submission_files_by_submission[
                    (int) ($submission["id"] ?? 0)
                ] ?? [];

            foreach ($attachment_rows as $attachment_row) {

                $attachment_path =
                    (string) ($attachment_row["file_path"] ?? "");
                $attachment_url = null;

                if (
                    $attachment_path !== ""
                    && $submission_project_root !== false
                    && $submission_upload_root !== false
                ) {

                    $attachment_candidate = realpath(
                        $submission_project_root
                        . DIRECTORY_SEPARATOR
                        . ltrim($attachment_path, "/\\")
                    );

                    if (
                        $attachment_candidate !== false
                        && is_file($attachment_candidate)
                        && strpos(
                            $attachment_candidate,
                            $submission_upload_root
                            . DIRECTORY_SEPARATOR
                        ) === 0
                    ) {

                        $attachment_url =
                            "../"
                            . str_replace(
                                DIRECTORY_SEPARATOR,
                                "/",
                                ltrim(
                                    str_replace(
                                        $submission_project_root,
                                        "",
                                        $attachment_candidate
                                    ),
                                    "/\\"
                                )
                            );

                    }

                }

                if ($attachment_url !== null) {

                    $submission["attachments"][] = [
                        "name" => $attachment_row["original_name"]
                            ?: basename($attachment_path),
                        "url" => $attachment_url
                    ];

                }

            }

        }

        unset($submission);

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
            substr(
                format_turkey_datetime(
                    $submission["submitted_at"]
                ),
                0,
                10
            ) === $today_turkey
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
    href="../css/style.css?v=upload-card-lavanta-20260819"
>




</head>


<body data-initial-section="<?= htmlspecialchars($active_section, ENT_QUOTES, "UTF-8") ?>">


<!-- ============================================================
     SIDEBAR
============================================================ -->

<div class="sidebar">

    <h2 class="brand-title">TODO APP</h2>

    <div class="sidebar-profile-summary">
        <div class="sidebar-profile-avatar">
            <?php if ($profile_image_url !== ""): ?>
                <img
                    src="<?= htmlspecialchars($profile_image_url, ENT_QUOTES, "UTF-8") ?>"
                    alt="Profil fotoğrafı"
                >
            <?php else: ?>
                <span class="profile-placeholder" aria-hidden="true"></span>
            <?php endif; ?>
        </div>
        <strong><?= htmlspecialchars($user_name, ENT_QUOTES, "UTF-8") ?></strong>
        <span>Kullanıcı</span>
    </div>

    <a
        href="#profil"
        class="menu-link"
        data-section="profil"
    >
        👤 Profil
    </a>

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

        <?= nl2br(
            htmlspecialchars(
                $error,
                ENT_QUOTES,
                "UTF-8"
            )
        ) ?>

    </div>

<?php endif; ?>


<!-- ============================================================
     PROFİL
============================================================ -->

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
                <?php if ($profile_image_url !== ""): ?>
                    <img
                        src="<?= htmlspecialchars($profile_image_url, ENT_QUOTES, "UTF-8") ?>"
                        alt="Profil fotoğrafı"
                    >
                <?php else: ?>
                    <span class="profile-placeholder" aria-hidden="true"></span>
                <?php endif; ?>
            </div>

            <div class="user-profile-tab-identity">
                <span class="user-profile-tab-eyebrow">HESAP BİLGİLERİ</span>
                <h2><?= htmlspecialchars($user_name, ENT_QUOTES, "UTF-8") ?></h2>
                <span class="profile-role-badge">Kullanıcı</span>
                <p>Görevlerinizi, günlük çalışmalarınızı ve bildirimlerinizi bu alandan takip edebilirsiniz.</p>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="success user-profile-tab-message">
                <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="error user-profile-tab-message">
                <?= nl2br(htmlspecialchars($error, ENT_QUOTES, "UTF-8")) ?>
            </div>
        <?php endif; ?>

        <div class="user-profile-tab-divider"></div>

        <div class="user-profile-tab-actions">
            <form method="POST" enctype="multipart/form-data" class="profile-upload-form">
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
                    for="userProfileImageInput"
                    class="upload-dropzone profile-upload-dropzone"
                    data-upload-dropzone
                >
                    <span class="upload-dropzone-icon" aria-hidden="true">+</span>
                    <span class="upload-dropzone-copy">
                        <strong>Fotoğraf yüklemek için tıklayın veya sürükleyin</strong>
                        <small>İzin verilen uzantılar: JPG, JPEG, PNG · En fazla 5 MB</small>
                        <span class="upload-dropzone-selection" id="userProfileFileName" data-upload-file-summary hidden>
                            Henüz dosya seçilmedi
                        </span>
                    </span>
                </label>

                <input
                    type="file"
                    id="userProfileImageInput"
                    name="profile_image"
                    accept=".jpg,.jpeg,.png"
                    class="profile-file-input upload-input"
                    data-upload-input
                >

                <button
                    type="submit"
                    class="profile-action-button success"
                    id="userProfileUploadSubmit"
                    hidden
                >
                    Fotoğrafı Yükle
                </button>
            </form>

            <?php if ($profile_image !== ""): ?>
                <form
                    method="POST"
                    class="profile-delete-form"
                    onsubmit="return confirm('Profil fotoğrafını silmek istediğinize emin misiniz?');"
                >
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, "UTF-8") ?>"
                    >
                    <input type="hidden" name="delete_profile_image" value="1">
                    <button type="submit" class="profile-action-button danger">
                        🗑 Fotoğrafı Sil
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <a
            href="#dashboard"
            class="profile-back-link menu-link"
            data-section="dashboard"
        >
            ← Dashboard'a Dön
        </a>
    </div>
</section>

<script>
const userProfileImageInput =
    document.getElementById("userProfileImageInput");
const userProfileUploadSubmit =
    document.getElementById("userProfileUploadSubmit");
const userProfileFileName =
    document.getElementById("userProfileFileName");

if (userProfileImageInput && userProfileUploadSubmit) {
    userProfileImageInput.addEventListener("change", function () {
        const hasFile = this.files && this.files.length > 0;

        userProfileUploadSubmit.hidden = !hasFile;

        if (userProfileFileName) {
            userProfileFileName.textContent = hasFile
                ? this.files[0].name
                : "Henüz dosya seçilmedi";
            userProfileFileName.hidden = !hasFile;
        }
    });
}
</script>

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


                        <span>

                            👤 Atayan:

                            <?= htmlspecialchars(
                                !empty($task["assigned_by_name"])
                                    ? $task["assigned_by_name"]
                                    : "Belirtilmemiş",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                            <?php if (!empty($task["assigned_by_username"])): ?>

                                (@<?= htmlspecialchars(
                                    $task["assigned_by_username"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>)

                            <?php endif; ?>

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


                                    <div class="upload-field-heading">
                                        <span>Dosya Ekleri</span>
                                        <small>(Opsiyonel · Maks. 10 MB / dosya)</small>
                                    </div>

                                    <label
                                        for="dailyTaskFiles_<?= (int) $current_task_id ?>"
                                        class="upload-dropzone"
                                        data-upload-dropzone
                                    >
                                        <span class="upload-dropzone-icon" aria-hidden="true">+</span>
                                        <span class="upload-dropzone-copy">
                                            <strong>Dosya yüklemek için tıklayın veya sürükleyin</strong>
                                            <small>İzin verilen uzantılar: PDF, DOC, DOCX, JPG, JPEG, PNG, XLS, XLSX · En fazla 10 MB</small>
                                            <span class="upload-dropzone-selection" data-upload-file-summary hidden>Henüz dosya seçilmedi</span>
                                        </span>
                                    </label>

                                    <input
                                        type="file"
                                        id="dailyTaskFiles_<?= (int) $current_task_id ?>"
                                        name="submission_files[]"
                                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx"
                                        multiple
                                        class="upload-input"
                                        data-upload-input
                                    >

                                    <div
                                        class="selected-files-list"
                                        data-selected-files-list
                                        aria-live="polite"
                                    ></div>


                                    <div
                                        class="error file-upload-error"
                                        role="alert"
                                        hidden
                                    ></div>


                                    


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
                                                    format_turkey_datetime(
                                                        $submission["submitted_at"]
                                                    ),
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


                                        <?php if (!empty($submission["attachments"])): ?>

                                            <div class="submission-files">

                                                <strong>
                                                    📎 Ek Dosyalar
                                                </strong>

                                                <?php foreach ($submission["attachments"] as $attachment): ?>

                                                    <a
                                                        href="<?= htmlspecialchars(
                                                            $attachment["url"],
                                                            ENT_QUOTES,
                                                            "UTF-8"
                                                        ) ?>"
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        class="submission-file"
                                                    >

                                                        <?= htmlspecialchars(
                                                            $attachment["name"],
                                                            ENT_QUOTES,
                                                            "UTF-8"
                                                        ) ?>

                                                    </a>

                                                <?php endforeach; ?>

                                            </div>

                                        <?php elseif (!empty($submission["file_path"])): ?>

                                            <a
                                                href="../<?= htmlspecialchars(
                                                    $submission["file_path"],
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ) ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
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

                    <div class="upload-field-heading">
                        <span>Dosya Eki</span>
                        <small>(Opsiyonel · Maks. 10 MB)</small>
                    </div>

                    <label
                        for="activityFileInput"
                        class="upload-dropzone"
                        data-upload-dropzone
                    >
                        <span class="upload-dropzone-icon" aria-hidden="true">+</span>
                        <span class="upload-dropzone-copy">
                            <strong>Dosya yüklemek için tıklayın veya sürükleyin</strong>
                            <small>İzin verilen uzantılar: PDF, DOC, DOCX, JPG, JPEG, PNG, XLS, XLSX · En fazla 10 MB</small>
                            <span class="upload-dropzone-selection" data-upload-file-summary hidden>Henüz dosya seçilmedi</span>
                        </span>
                    </label>

                    <input
                        type="file"
                        id="activityFileInput"
                        name="activity_file"
                        accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx"
                        class="upload-input"
                        data-upload-input
                    >

                    <div
                        class="error file-upload-error"
                        role="alert"
                        hidden
                    ></div>

                    

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
                            format_turkey_datetime(
                                $activity["created_at"]
                            ),
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
                        format_turkey_datetime(
                            $notification["created_at"]
                        ),
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>

                </div>


                <div class="notification-card-actions">

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



// ============================================================
// DOSYA TÜRÜNÜ FORM GÖNDERİLMEDEN ÖNCE KONTROL ET
// ============================================================

const allowedUploadExtensions = new Set([
    "pdf",
    "doc",
    "docx",
    "jpg",
    "jpeg",
    "png",
    "xls",
    "xlsx"
]);

const allowedUploadMimeTypes = new Set([
    "application/pdf",
    "application/msword",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "image/jpeg",
    "image/png",
    "application/vnd.ms-excel",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
]);

const uploadTypeErrorHtml =
    "Yüklenen dosya şu dosya türlerinden biri olmalıdır: "
    + "pdf, doc, docx, jpg, jpeg, png, xls, xlsx."
    + "<br>"
    + "Yüklenen dosya şu dosya türlerinden biri olmalıdır: "
    + "application/pdf, application/msword, "
    + "application/vnd.openxmlformats-officedocument.wordprocessingml.document, "
    + "image/jpeg, image/png, application/vnd.ms-excel, "
    + "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.";

function getSelectedFiles(input) {

    return Array.from(input.files || []);

}


function showUploadError(input, message) {

    const form = input.form;
    const errorBox = form
        ? form.querySelector(".file-upload-error")
        : null;

    if (!errorBox) {
        return;
    }

    errorBox.innerHTML = message;
    errorBox.hidden = false;

}


function updateUploadCardSummary(input) {

    const form = input.form;
    const summary = form
        ? form.querySelector("[data-upload-file-summary]")
        : null;
    const files = getSelectedFiles(input);

    if (!summary) {
        return;
    }

    if (files.length === 0) {
        summary.textContent = "Henüz dosya seçilmedi";
        summary.hidden = true;
        return;
    }

    summary.textContent = files.length === 1
        ? files[0].name
        : files.length + " dosya seçildi";
    summary.hidden = false;

}


function updateSelectedFileList(input) {

    const form = input.form;
    const list = form
        ? form.querySelector("[data-selected-files-list]")
        : null;

    if (!list) {
        return;
    }

    list.innerHTML = "";

    getSelectedFiles(input).forEach(function (file, index) {

        const item = document.createElement("div");
        item.className = "selected-file-item";

        const name = document.createElement("span");
        name.className = "selected-file-name";
        name.textContent = file.name;

        const removeButton = document.createElement("button");
        removeButton.type = "button";
        removeButton.className = "remove-selected-file";
        removeButton.textContent = "Kaldır";
        removeButton.dataset.fileIndex = String(index);

        removeButton.addEventListener("click", function () {

            const dataTransfer = new DataTransfer();

            getSelectedFiles(input).forEach(function (selectedFile, fileIndex) {

                if (fileIndex !== index) {
                    dataTransfer.items.add(selectedFile);
                }

            });

            input.files = dataTransfer.files;
            updateSelectedFileList(input);
            updateUploadCardSummary(input);
            validateUploadInput(input);

        });

        item.appendChild(name);
        item.appendChild(removeButton);
        list.appendChild(item);

    });

}


function validateUploadInput(input) {

    const form = input.form;
    const errorBox = form
        ? form.querySelector(".file-upload-error")
        : null;
    const files = getSelectedFiles(input);

    if (errorBox) {
        errorBox.hidden = true;
        errorBox.innerHTML = "";
    }

    if (files.length === 0) {
        return true;
    }

    if (files.length > 10) {

        showUploadError(
            input,
            "Bir gönderimde en fazla 10 dosya seçebilirsiniz."
        );

        return false;

    }

    for (const file of files) {

        const fileName = file.name.toLowerCase();
        const lastDot = fileName.lastIndexOf(".");
        const extension = lastDot >= 0
            ? fileName.substring(lastDot + 1)
            : "";
        const mimeType = file.type.toLowerCase();

        const isValid =
            allowedUploadExtensions.has(extension) &&
            allowedUploadMimeTypes.has(mimeType);

        if (!isValid) {

            showUploadError(input, uploadTypeErrorHtml);
            return false;

        }

    }

    return true;
}

document.querySelectorAll("input[data-upload-input]").forEach(function (input) {

    const dropzone = input.id
        ? document.querySelector(
            'label[data-upload-dropzone][for="' + input.id + '"]'
        )
        : null;

    updateUploadCardSummary(input);

    if (dropzone) {

        ["dragenter", "dragover"].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
                event.preventDefault();
                event.stopPropagation();
                dropzone.classList.add("is-dragover");
            });
        });

        ["dragleave", "dragend"].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
                event.preventDefault();
                event.stopPropagation();
                dropzone.classList.remove("is-dragover");
            });
        });

        dropzone.addEventListener("drop", function (event) {
            event.preventDefault();
            event.stopPropagation();
            dropzone.classList.remove("is-dragover");

            const droppedFiles = event.dataTransfer && event.dataTransfer.files
                ? Array.from(event.dataTransfer.files)
                : [];

            if (droppedFiles.length === 0) {
                return;
            }

            const dataTransfer = new DataTransfer();
            const filesToAdd = input.multiple
                ? droppedFiles
                : droppedFiles.slice(0, 1);

            filesToAdd.forEach(function (file) {
                dataTransfer.items.add(file);
            });

            input.files = dataTransfer.files;
            input.dispatchEvent(new Event("change", { bubbles: true }));
        });

    }

    input.addEventListener("change", function () {
        updateUploadCardSummary(input);

        if (
            input.name === "activity_file"
            || input.name === "submission_files[]"
        ) {
            updateSelectedFileList(input);
            validateUploadInput(input);
        }
    });

    if (
        input.form
        && (
            input.name === "activity_file"
            || input.name === "submission_files[]"
        )
    ) {
        input.form.addEventListener("submit", function (event) {

            if (!validateUploadInput(input)) {
                event.preventDefault();

                const errorBox = input.form.querySelector(
                    ".file-upload-error"
                );

                if (errorBox) {
                    errorBox.scrollIntoView({
                        behavior: "smooth",
                        block: "center"
                    });
                }
            }
        });
    }

});

</script>


</body>

</html>