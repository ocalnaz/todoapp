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
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    echo "Bu sayfaya erişim yetkiniz yok.";
    exit;
}


$admin_id = (int) $_SESSION["user_id"];

$message = "";
$error = "";


// ==================================================
// CSRF TOKEN
// ==================================================

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );
}

$csrf_token = $_SESSION["csrf_token"];


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
                empty($full_name) ||
                empty($username) ||
                empty($password)
            ) {

                $error =
                    "Lütfen tüm kullanıcı alanlarını doldurun.";

            } elseif (strlen($password) < 6) {

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
                                    strlen($new_password) < 6
                                ) {

                                    $error =
                                        "Yeni şifre en az 6 karakter olmalıdır.";

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

            $assigned_to =
                (int) ($_POST["assigned_to"] ?? 0);

            $due_date =
                $_POST["due_date"] ?? "";


            if (
                empty($title) ||
                empty($description) ||
                empty($assigned_to) ||
                empty($due_date)
            ) {

                $error =
                    "Lütfen tüm görev alanlarını doldurun.";

            } else {

                try {

                    $user_stmt = $db->prepare("
                        SELECT id, full_name
                        FROM users
                        WHERE id = ?
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
                                created_at
                            )
                            VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                        ");

                        $stmt->execute([
                            $title,
                            $description,
                            $assigned_to,
                            $admin_id,
                            $due_date,
                            "bekliyor"
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

            $assigned_to =
                (int) ($_POST["edit_assigned_to"] ?? 0);

            $due_date =
                $_POST["edit_due_date"] ?? "";


            if (
                empty($task_id) ||
                empty($title) ||
                empty($description) ||
                empty($assigned_to) ||
                empty($due_date)
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
                                    due_date = ?
                                WHERE id = ?
                                AND assigned_by = ?
                            ");

                            $stmt->execute([
                                $title,
                                $description,
                                $assigned_to,
                                $due_date,
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

                        $delete_submissions =
                            $db->prepare("
                                DELETE FROM task_submissions
                                WHERE task_id = ?
                            ");

                        $delete_submissions->execute([
                            $task_id
                        ]);


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
                                DELETE FROM tasks
                                WHERE id = ?
                                AND assigned_by = ?
                            ");

                        $delete_task->execute([
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
        t.created_at,
        u.full_name AS user_name,
        u.username AS username
    FROM tasks t

    INNER JOIN users u
        ON t.assigned_to = u.id

    WHERE t.assigned_by = ?

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
        t.title AS task_title,
        u.full_name AS user_name
    FROM task_submissions ts

    INNER JOIN tasks t
        ON ts.task_id = t.id

    INNER JOIN users u
        ON ts.user_id = u.id

    WHERE t.assigned_by = ?

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
    href="../css/style.css"
>

<style>

/* ==================================================
   BÖLÜMLER
================================================== */

.panel-section {
    display: none;
}

.panel-section.active-section {
    display: block;
    animation: sectionFade .25s ease;
}

@keyframes sectionFade {

    from {
        opacity: 0;
        transform: translateY(8px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }

}


/* ==================================================
   AKTİF MENÜ
================================================== */

.sidebar a.active {

    background:
        rgba(99,102,241,.18);

    color:#fff;

    border-left:
        3px solid #6366f1;
}


/* ==================================================
   BAŞLIKLAR
================================================== */

.section-title {
    margin-bottom:25px;
}

.section-title h1 {
    margin-bottom:8px;
}

.section-title p {
    opacity:.7;
}


/* ==================================================
   DASHBOARD
================================================== */

.dashboard-cards {

    display:grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(200px,1fr)
        );

    gap:20px;

    margin-top:30px;
}

.dashboard-card {

    padding:25px;

    border-radius:16px;
}

.dashboard-card h3 {
    margin-bottom:10px;
}

.dashboard-card h1 {

    font-size:36px;

    margin:0;
}


/* ==================================================
   KULLANICI KARTLARI
================================================== */

.user-list {

    display:grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(260px,1fr)
        );

    gap:18px;

    margin-top:25px;
}

.user-card {

    padding:20px;

    border-radius:16px;

    border:
        1px solid
        rgba(99,102,241,.15);

    background:
        rgba(99,102,241,.04);

    transition:.2s;
}

.user-card:hover {

    transform:translateY(-3px);

    box-shadow:
        0 10px 25px
        rgba(0,0,0,.08);
}


/* ==================================================
   ADMIN KARTI
================================================== */

.user-card.admin-card {

    border:
        1px solid
        rgba(245,158,11,.35);

    background:
        rgba(245,158,11,.08);
}

.user-role {

    display:inline-block;

    margin-top:10px;

    padding:5px 10px;

    border-radius:20px;

    font-size:12px;

    font-weight:700;
}

.user-role.admin {

    background:
        rgba(245,158,11,.15);

    color:#f59e0b;
}

.user-role.user {

    background:
        rgba(99,102,241,.15);

    color:#6366f1;
}


/* ==================================================
   KULLANICI İŞLEMLERİ
================================================== */

.user-actions {

    display:flex;

    gap:8px;

    flex-wrap:wrap;

    margin-top:16px;
}

.user-edit-box {

    display:none;

    margin-top:18px;

    padding:18px;

    border-radius:14px;

    background:
        rgba(99,102,241,.05);

    border:
        1px solid
        rgba(99,102,241,.15);
}

.user-edit-box.show {
    display:block;
}


/* ==================================================
   SİL BUTONU
================================================== */

.delete-button {

    background:
        rgba(239,68,68,.12);

    color:#ef4444;

    border:
        1px solid
        rgba(239,68,68,.25);

    padding:10px 15px;

    border-radius:10px;

    cursor:pointer;

    font-weight:600;
}

.delete-button:hover {

    background:
        rgba(239,68,68,.20);
}


/* ==================================================
   TEMA
================================================== */

.theme-toggle {

    position:fixed;

    right:25px;
    bottom:25px;

    width:52px;
    height:52px;

    border-radius:50%;

    border:none;

    cursor:pointer;

    display:inline-flex;
    align-items:center;
    justify-content:center;

    padding:0;
    line-height:1;
    text-align:center;

    font-size:22px;

    z-index:9999;

    box-shadow:
        0 8px 25px
        rgba(0,0,0,.20);
}


/* ==================================================
   DOSYA
================================================== */

.activity-file {

    margin-top:20px;

    padding:18px;

    border:
        1px solid
        rgba(255,255,255,.10);

    border-radius:12px;
}

.formatted-file-name {

    display:inline-block;

    margin-top:12px;

    padding:10px 14px;

    border-radius:10px;

    background:
        rgba(99,102,241,.10);

    border:
        1px solid
        rgba(99,102,241,.20);

    font-size:14px;

    word-break:break-word;
}

.file-meta {

    margin-top:10px;

    font-size:13px;

    opacity:.65;
}


/* ==================================================
   SÜRESİ GEÇMİŞ
================================================== */

.status.expired {

    background:
        rgba(239,68,68,.15);

    color:#ef4444;

    border:
        1px solid
        rgba(239,68,68,.30);
}

.expired-warning {

    margin-top:15px;

    padding:12px 15px;

    border-radius:10px;

    background:
        rgba(239,68,68,.08);

    border:
        1px solid
        rgba(239,68,68,.20);

    color:#ef4444;

    font-weight:600;
}


/* ==================================================
   GÖREV DÜZENLEME
================================================== */

.edit-task-box {

    margin-top:20px;

    padding:20px;

    border-radius:15px;

    background:
        rgba(99,102,241,.05);

    border:
        1px solid
        rgba(99,102,241,.15);

    display:none;
}

.edit-task-box.show {
    display:block;
}

.edit-task-box h3 {
    margin-bottom:18px;
}


/* ==================================================
   BUTONLAR
================================================== */

.task-actions {

    display:flex;

    gap:10px;

    flex-wrap:wrap;

    margin-top:20px;
}

.secondary-button {

    background:
        rgba(99,102,241,.12);

    color:inherit;

    border:
        1px solid
        rgba(99,102,241,.25);

    padding:10px 16px;

    border-radius:10px;

    cursor:pointer;
}

.cancel-edit {

    background:
        rgba(239,68,68,.10);

    color:#ef4444;

    border:
        1px solid
        rgba(239,68,68,.20);

    padding:10px 16px;

    border-radius:10px;

    cursor:pointer;
}


/* ==================================================
   FORM
================================================== */

.form-grid {

    display:grid;

    grid-template-columns:
        repeat(
            auto-fit,
            minmax(250px,1fr)
        );

    gap:20px;
}

.form-group {
    margin-bottom:18px;
}

.form-group label {

    display:block;

    margin-bottom:8px;

    font-weight:600;
}




/* ==================================================
   FİLTRELEME
================================================== */

.filter-box {
    margin: 0 0 25px 0;
    padding: 18px;
    border-radius: 16px;
    background: rgba(99,102,241,.05);
    border: 1px solid rgba(99,102,241,.14);
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 12px;
    align-items: end;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.filter-group label {
    font-size: 12px;
    font-weight: 700;
    opacity: .7;
}

.filter-group input,
.filter-group select {
    width: 100%;
    box-sizing: border-box;
}

.filter-reset {
    min-height: 44px;
    padding: 10px 15px;
    border-radius: 10px;
    border: 1px solid rgba(239,68,68,.22);
    background: rgba(239,68,68,.09);
    color: #ef4444;
    cursor: pointer;
    font-weight: 600;
}

.filter-result-count {
    margin-top: 12px;
    font-size: 13px;
    opacity: .6;
}

.filter-empty {
    display: none;
    margin-top: 15px;
    padding: 18px;
    text-align: center;
    border-radius: 12px;
    background: rgba(99,102,241,.05);
    border: 1px dashed rgba(99,102,241,.20);
    opacity: .75;
}

.filter-item.is-hidden {
    display: none !important;
}

/* ==================================================
   MOBİL
================================================== */

@media(max-width:700px) {

    .task-actions,
    .user-actions {

        flex-direction:column;
    }

}

</style>

</head>


<body>


<!-- ==================================================
     SIDEBAR
================================================== -->

<div class="sidebar">

    <h2>
        TODO APP
    </h2>


    <a
        href="#dashboard"
        class="menu-link active"
        data-section="dashboard"
    >
        📊 Dashboard
    </a>


    <a
        href="#raporlar"
        class="menu-link"
        data-section="raporlar"
    >
        📊 Raporlar
    </a>


    <a
        href="#kullanicilar"
        class="menu-link"
        data-section="kullanicilar"
    >
        👥 Kullanıcılar
    </a>


    <a
        href="#gorev-ata"
        class="menu-link"
        data-section="gorev-ata"
    >
        ➕ Görev Ata
    </a>


    <a
        href="#gorevler"
        class="menu-link"
        data-section="gorevler"
    >
        📋 Görevler
    </a>


    <a
        href="#calismalar"
        class="menu-link"
        data-section="calismalar"
    >
        📤 Görev Çalışmaları
    </a>


    <a
        href="#kullanici-calismalari"
        class="menu-link"
        data-section="kullanici-calismalari"
    >
        📝 Kullanıcı Çalışmaları
    </a>


    <a
        href="#bildirimler"
        class="menu-link"
        data-section="bildirimler"
    >

        🔔 Bildirimler

        <?php if ($unread_count > 0): ?>

            <span
                style="
                    margin-left:5px;
                    padding:3px 8px;
                    border-radius:20px;
                    font-size:12px;
                    background:#ef4444;
                    color:white;
                "
            >

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
     RAPORLAR
================================================== -->

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
            <button type="button" class="filter-reset" id="resetTaskFilter">↺ Filtreleri Temizle</button>
        </div>
        <div class="filter-result-count" id="taskFilterCount"></div>
    </div>

    <div class="filter-empty" id="taskFilterEmpty">🔎 Filtrelere uygun görev bulunamadı.</div>

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
            >


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
                        $task["user_name"]
                    ) ?>

                    <?php if (!empty($task["username"])): ?>

                        <span style="opacity:.6;">

                            (@<?= htmlspecialchars(
                                $task["username"]
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


        <?php foreach (
            $notifications
            as $notification
        ): ?>


            <div
                class="box filter-item notification-filter-item"
                data-notification-text="<?= htmlspecialchars(strtolower($notification["title"] . " " . $notification["message"]), ENT_QUOTES, "UTF-8") ?>"
                data-notification-status="<?= (int) $notification["is_read"] === 0 ? "unread" : "read" ?>"
            >

                <div
                    style="
                        display:flex;
                        justify-content:space-between;
                        align-items:center;
                        gap:15px;
                    "
                >

                    <h2>

                        <?= htmlspecialchars(
                            $notification["title"]
                        ) ?>

                    </h2>


                    <?php if (
                        (int) $notification["is_read"] === 0
                    ): ?>

                        <span
                            class="status incelemede"
                        >

                            Yeni

                        </span>

                    <?php endif; ?>

                </div>


                <div class="work">

                    <?= nl2br(
                        htmlspecialchars(
                            $notification["message"]
                        )
                    ) ?>

                </div>


                <div class="date">

                    📅

                    <?= htmlspecialchars(
                        $notification["created_at"]
                    ) ?>

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

                        event.preventDefault();

                        const sectionName =
                            this.dataset.section;

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


        if (hash) {

            const sectionExists =
                document.getElementById(
                    "section-" + hash
                );


            if (sectionExists) {

                showSection(hash);

            }

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

        function apply() {
            const query = search ? search.value.trim().toLocaleLowerCase("tr-TR") : "";
            let visible = 0;

            items.forEach(function (item) {
                let show = true;

                if (query && !String(item.dataset[config.searchData] || "").toLocaleLowerCase("tr-TR").includes(query)) {
                    show = false;
                }

                (config.selectData || []).forEach(function (dataKey, index) {
                    const selected = selects[index] ? selects[index].value : "all";
                    if (selected !== "all" && String(item.dataset[dataKey] || "") !== selected) {
                        show = false;
                    }
                });

                item.classList.toggle("is-hidden", !show);
                if (show) visible++;
            });

            if (count) {
                count.textContent = visible + " sonuç gösteriliyor";
            }
            if (empty) {
                empty.style.display = visible === 0 ? "block" : "none";
            }
        }

        if (search) search.addEventListener("input", apply);
        selects.forEach(select => select.addEventListener("change", apply));

        if (reset) {
            reset.addEventListener("click", function () {
                if (search) search.value = "";
                selects.forEach(select => select.value = "all");
                apply();
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
        selectIds: ["taskUserFilter", "taskStatusFilter"],
        searchData: "taskTitle",
        selectData: ["taskUser", "taskStatus"],
        resetId: "resetTaskFilter",
        countId: "taskFilterCount",
        emptyId: "taskFilterEmpty"
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