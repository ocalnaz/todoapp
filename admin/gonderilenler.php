<?php

require_once __DIR__ . "/../config/session.php";

require_once "../config/database.php";


// ==================================================
// SQLITE ÇOKLU DOSYA TABLOSU
// ==================================================

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

    // Tablo daha önce SQL şemasıyla oluşturulduysa işlem normal şekilde sürer.

}


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


$admin_id = (int) $_SESSION["user_id"];


// ==================================================
// CSRF TOKEN
// ==================================================

if (empty($_SESSION["csrf_token"])) {

    $_SESSION["csrf_token"] = bin2hex(
        random_bytes(32)
    );

}


$message = "";
$error = "";


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


// ==================================================
// POST İŞLEMLERİ
// ==================================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {


    // ==================================================
    // CSRF KONTROLÜ
    // ==================================================

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


        $submission_id =
            (int) ($_POST["submission_id"] ?? 0);

        $action =
            $_POST["action"] ?? "";


        if ($submission_id <= 0) {

            $error = "Geçersiz gönderi.";

        } else {


            try {


                // ==================================================
                // GÖNDERİYİ GETİR
                // ==================================================

                $stmt = $db->prepare("
                    SELECT
                        ts.id,
                        ts.task_id,
                        ts.user_id,
                        ts.status,
                        ts.file_path,
                        t.title
                    FROM task_submissions ts
                    INNER JOIN tasks t
                        ON ts.task_id = t.id
                    WHERE ts.id = ?
                      AND t.assigned_by = ?
                ");

                $stmt->execute([
                    $submission_id,
                    $admin_id
                ]);

                $submission =
                    $stmt->fetch(PDO::FETCH_ASSOC);


                if (!$submission) {

                    $error =
                        "Gönderilen çalışma bulunamadı.";

                } else {


                    $reviewable_statuses = [
                        "incelemede",
                        "gönderildi",
                        "revize istendi"
                    ];


                    if (
                        in_array(
                            $action,
                            ["approve", "revise"],
                            true
                        )
                        && !in_array(
                            $submission["status"],
                            $reviewable_statuses,
                            true
                        )
                    ) {

                        $error =
                            "Bu gönderi artık incelenebilir durumda değil.";

                    }


                    // ==================================================
                    // ÇALIŞMAYI ONAYLA
                    // ==================================================

                    elseif ($action === "approve") {


                        $db->beginTransaction();


                        // Gönderiyi onayla
                        $stmt = $db->prepare("
                            UPDATE task_submissions
                            SET status = ?
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            "onaylandı",
                            $submission_id
                        ]);


                        // Görevin durumunu da onaylandı yap
                        $stmt = $db->prepare("
                            UPDATE tasks
                            SET status = ?
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            "onaylandı",
                            $submission["task_id"]
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
                            $submission["user_id"],
                            "Görev Onaylandı",
                            "Göreviniz onaylandı: " . $submission["title"],
                            0
                        ]);


                        $db->commit();


                        $message =
                            "Çalışma başarıyla onaylandı.";

                    }


                    // ==================================================
                    // REVİZE İSTE
                    // ==================================================

                    elseif ($action === "revise") {


                        $db->beginTransaction();


                        // Gönderiyi revize istendi yap
                        $stmt = $db->prepare("
                            UPDATE task_submissions
                            SET status = ?
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            "revize istendi",
                            $submission_id
                        ]);


                        // Görevi revizyon durumuna getir
                        $stmt = $db->prepare("
                            UPDATE tasks
                            SET status = ?
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            "revizyon",
                            $submission["task_id"]
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
                            $submission["user_id"],
                            "Revizyon İstendi",
                            "Göreviniz için revizyon istendi: " . $submission["title"],
                            0
                        ]);


                        $db->commit();


                        $message =
                            "Kullanıcıdan revize istendi.";

                    }


                    // ==================================================
                    // GÖNDERİYİ SİL
                    // ==================================================

                    elseif ($action === "delete") {

                        $paths_to_delete = [];

                        if (!empty($submission["file_path"])) {
                            $paths_to_delete[] =
                                (string) $submission["file_path"];
                        }

                        try {

                            $file_path_stmt = $db->prepare("
                                SELECT file_path
                                FROM task_submission_files
                                WHERE submission_id = ?
                            ");

                            $file_path_stmt->execute([
                                $submission_id
                            ]);

                            foreach (
                                $file_path_stmt->fetchAll(PDO::FETCH_COLUMN)
                                as $extra_file_path
                            ) {

                                if ((string) $extra_file_path !== "") {
                                    $paths_to_delete[] =
                                        (string) $extra_file_path;
                                }

                            }

                        } catch (PDOException $e) {

                            // Yardımcı tablo yoksa eski tek dosya silme akışı sürer.

                        }

                        try {

                            $delete_file_rows_stmt = $db->prepare("
                                DELETE FROM task_submission_files
                                WHERE submission_id = ?
                            ");

                            $delete_file_rows_stmt->execute([
                                $submission_id
                            ]);

                        } catch (PDOException $e) {

                            // Yardımcı tablo yoksa eski tek dosya akışı sürer.

                        }

                        $stmt = $db->prepare("
                            DELETE FROM task_submissions
                            WHERE id = ?
                        ");

                        $stmt->execute([
                            $submission_id
                        ]);

                        $project_root_for_delete = realpath(__DIR__ . "/..");
                        $upload_root_for_delete = realpath(
                            __DIR__ . "/../uploads/task_submissions"
                        );

                        if (
                            $project_root_for_delete !== false
                            && $upload_root_for_delete !== false
                        ) {

                            foreach (
                                array_unique($paths_to_delete)
                                as $path_to_delete
                            ) {

                                $candidate_to_delete = realpath(
                                    $project_root_for_delete
                                    . DIRECTORY_SEPARATOR
                                    . ltrim($path_to_delete, "/\\")
                                );

                                if (
                                    $candidate_to_delete !== false
                                    && is_file($candidate_to_delete)
                                    && strpos(
                                        $candidate_to_delete,
                                        $upload_root_for_delete
                                        . DIRECTORY_SEPARATOR
                                    ) === 0
                                ) {

                                    @unlink($candidate_to_delete);

                                }

                            }

                        }

                        $message =
                            "Gönderilen çalışma silindi.";

                    }


                    else {

                        $error =
                            "Geçersiz işlem.";

                    }

                }


            } catch (PDOException $e) {


                if ($db->inTransaction()) {

                    $db->rollBack();

                }


                $error =
                    "İşlem sırasında bir hata oluştu.";

            }

        }

    }

}


// ==================================================
// GÖNDERİLEN ÇALIŞMALARI GETİR
// ==================================================

$stmt = $db->prepare("
    SELECT

        task_submissions.id,
        task_submissions.task_id,
        task_submissions.user_id,
        task_submissions.content,
        task_submissions.created_at,
        task_submissions.status,
        task_submissions.file_name,
        task_submissions.file_path,

        tasks.title,
        tasks.description,
        tasks.due_date,
        tasks.status AS task_status,

        users.full_name,
        users.username,
        assigned_by_user.full_name AS assigned_by_name,
        assigned_by_user.username AS assigned_by_username

    FROM task_submissions

    INNER JOIN tasks
        ON task_submissions.task_id = tasks.id

    INNER JOIN users
        ON task_submissions.user_id = users.id

    LEFT JOIN users assigned_by_user
        ON tasks.assigned_by = assigned_by_user.id

    WHERE tasks.assigned_by = :admin_id

    ORDER BY task_submissions.id DESC
");

$stmt->execute([
    ":admin_id" => $admin_id
]);

$submissions =
    $stmt->fetchAll(PDO::FETCH_ASSOC);


$submission_files_by_submission = [];
$submission_ids = [];

foreach ($submissions as $submission_row) {

    $current_submission_id =
        (int) ($submission_row["id"] ?? 0);

    if ($current_submission_id > 0) {
        $submission_ids[] = $current_submission_id;
    }

}

$submission_ids = array_values(
    array_unique($submission_ids)
);

$attachment_name_column = "original_name";

try {

    $attachment_columns_stmt = $db->query(
        "PRAGMA table_info(task_submission_files)"
    );

    $attachment_columns = $attachment_columns_stmt->fetchAll(
        PDO::FETCH_COLUMN,
        1
    );

    if (
        !in_array("original_name", $attachment_columns, true)
        && in_array("file_name", $attachment_columns, true)
    ) {

        $attachment_name_column = "file_name";

    }

} catch (PDOException $e) {

    // Varsayılan şema original_name kolonunu kullanır.

}

if (!empty($submission_ids)) {

    try {

        $file_placeholders = implode(
            ",",
            array_fill(0, count($submission_ids), "?")
        );

        $submission_files_stmt = $db->prepare(
            "SELECT id, submission_id, "
            . $attachment_name_column
            . " AS original_name, file_path "
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

        // Yardımcı tablo kurulmamışsa eski tek dosya gösterimi çalışmaya devam eder.
        $submission_files_by_submission = [];

    }

}


$project_root = realpath(__DIR__ . "/..");
$submission_upload_root = realpath(
    __DIR__ . "/../uploads/task_submissions"
);


foreach ($submissions as &$submission) {

    $submission["file_url"] = null;

    $stored_file_path =
        (string) ($submission["file_path"] ?? "");


    if (
        $stored_file_path !== ""
        && $project_root !== false
        && $submission_upload_root !== false
    ) {

        $candidate_path = realpath(
            $project_root
            . DIRECTORY_SEPARATOR
            . ltrim($stored_file_path, "/\\\\")
        );


        if (
            $candidate_path !== false
            && is_file($candidate_path)
            && (
                $candidate_path === $submission_upload_root
                || strpos(
                    $candidate_path,
                    $submission_upload_root . DIRECTORY_SEPARATOR
                ) === 0
            )
        ) {

            $relative_path = ltrim(
                str_replace(
                    $project_root,
                    "",
                    $candidate_path
                ),
                "/\\\\"
            );

            $submission["file_url"] =
                "../task_file.php?submission_id="
                . (int) ($submission["id"] ?? 0);
        }
    }

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
            && $project_root !== false
            && $submission_upload_root !== false
        ) {

            $attachment_candidate = realpath(
                $project_root
                . DIRECTORY_SEPARATOR
                . ltrim($attachment_path, "/\\")
            );

            if (
                $attachment_candidate !== false
                && is_file($attachment_candidate)
                && strpos(
                    $attachment_candidate,
                    $submission_upload_root . DIRECTORY_SEPARATOR
                ) === 0
            ) {

                $attachment_relative = ltrim(
                    str_replace(
                        $project_root,
                        "",
                        $attachment_candidate
                    ),
                    "/\\"
                );

                $attachment_url =
                    "../task_file.php?id="
                    . (int) ($attachment_row["id"] ?? 0);

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
        Gönderilenler - Todo App
    </title>

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

    <h2>
        TODO APP
    </h2>


    <a href="dashboard.php">

        🏠 Dashboard

    </a>


    <a href="kullanicilar.php">

        🧒🏻👩🏻 Kullanıcılar

    </a>


    <a href="gorevler.php">

        📒 Görevler

    </a>


    <a
        href="gonderilenler.php"
        class="active"
    >

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


        <!-- ==================================================
             BAŞLIK
        ================================================== -->

        <div class="page-header">

            <h1>
                Gönderilen Çalışmalar
            </h1>

            <p>
                Kullanıcıların görevler için gönderdikleri
                çalışmaları buradan inceleyebilirsiniz.
            </p>

        </div>



        <!-- ==================================================
             MESAJLAR
        ================================================== -->

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
             GÖNDERİLER
        ================================================== -->

        <?php if (empty($submissions)): ?>


            <div class="empty">

                <h2>
                    Henüz gönderilen çalışma yok.
                </h2>

                <p>
                    Kullanıcılar görevlerine çalışma
                    gönderdiklerinde burada görünecek.
                </p>

            </div>


        <?php else: ?>


            <?php foreach ($submissions as $submission): ?>


                <div class="box">


                    <!-- ==================================================
                         GÖREV BAŞLIĞI
                    ================================================== -->

                    <h2>

                        📋

                        <?= htmlspecialchars(
                            $submission["title"]
                        ) ?>

                    </h2>



                    <!-- ==================================================
                         KULLANICI
                    ================================================== -->

                    <div class="user-info">

                        👤

                        <strong>

                            <?= htmlspecialchars(
                                $submission["full_name"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                        </strong>

                        (@<?= htmlspecialchars(
                            $submission["username"],
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>)

                    </div>


                    <div class="user-info">

                        🧑‍💼

                        <strong>
                            Atayan:
                        </strong>

                        <?= htmlspecialchars(
                            $submission["assigned_by_name"] ?? "-",
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                        <?php if (!empty($submission["assigned_by_username"])): ?>

                            (@<?= htmlspecialchars(
                                $submission["assigned_by_username"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>)

                        <?php endif; ?>

                    </div>


                    <hr>



                    <!-- ==================================================
                         GÖREV AÇIKLAMASI
                    ================================================== -->

                    <div class="task-description">

                        <strong>
                            Görev Açıklaması
                        </strong>

                        <p>

                            <?= nl2br(
                                htmlspecialchars(
                                    $submission["description"]
                                )
                            ) ?>

                        </p>

                    </div>



                    <!-- ==================================================
                         SON TARİH
                    ================================================== -->

                    <div class="date">

                        📅

                        <strong>
                            Son Tarih:
                        </strong>

                        <?= htmlspecialchars(
                            $submission["due_date"] ?? "-"
                        ) ?>

                    </div>


                    <br>



                    <!-- ==================================================
                         KULLANICININ ÇALIŞMASI
                    ================================================== -->

                    <strong>

                        📝 Kullanıcının Gönderdiği Çalışma

                    </strong>


                    <div class="work">

                        <?= nl2br(
                            htmlspecialchars(
                                $submission["content"]
                            )
                        ) ?>

                    </div>


<?php if (!empty($submission["attachments"])): ?>

                        <div class="submission-files">

                            📎

                            <strong>
                                Ek Dosyalar
                                (<?= count($submission["attachments"]) ?>):
                            </strong>

                            <?php foreach ($submission["attachments"] as $attachment): ?>

                                <div class="submission-file-item submission-file">

                                    <span class="submission-file-name">
                                        <?= htmlspecialchars(
                                            $attachment["name"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>
                                    </span>

                                    <a
                                        href="<?= htmlspecialchars(
                                            $attachment["url"],
                                            ENT_QUOTES,
                                            "UTF-8"
                                        ) ?>"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Dosyayı Aç
                                    </a>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php elseif (!empty($submission["file_url"])): ?>

                        <div class="date">

                            📎

                            <strong>
                                Ek Dosya:
                            </strong>

                            <?= htmlspecialchars(
                                !empty($submission["file_name"])
                                    ? $submission["file_name"]
                                    : basename(
                                        (string) ($submission["file_path"] ?? "")
                                    ),
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>

                            <a
                                href="<?= htmlspecialchars(
                                    $submission["file_url"],
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Dosyayı Aç
                            </a>

                        </div>

                    <?php endif; ?>



                    <!-- ==================================================
                         GÖNDERİM TARİHİ
                    ================================================== -->

                    <div class="date">

                        📅

                        <strong>
                            Gönderim Tarihi:
                        </strong>

                        <?= htmlspecialchars(
                            format_turkey_datetime(
                                $submission["created_at"]
                            ),
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </div>



                    <!-- ==================================================
                         DURUM
                    ================================================== -->

                    <div class="submission-status">

                        <strong>
                            Durum:
                        </strong>


                        <?php

                        $statusClass = "status";


                        if (
                            $submission["status"]
                            === "incelemede"
                            ||
                            $submission["status"]
                            === "gönderildi"
                        ) {

                            $statusClass .= " incelemede";

                        } elseif (
                            $submission["status"]
                            === "onaylandı"
                        ) {

                            $statusClass .= " onaylandi";

                        } elseif (
                            $submission["status"]
                            === "revize istendi"
                        ) {

                            $statusClass .= " revize";

                        }

                        ?>


                        <span
                            class="<?= $statusClass ?>"
                        >

                            <?= htmlspecialchars(
                                $submission["status"]
                            ) ?>

                        </span>

                    </div>



                    <!-- ==================================================
                         İŞLEMLER
                    ================================================== -->

                    <div class="actions submission-actions">


                                                <?php if (
                            $submission["status"]
                            === "incelemede"
                            ||
                            $submission["status"]
                            === "gönderildi"
                            ||
                            $submission["status"]
                            === "revize istendi"
                        ): ?>


                            <!-- ==============================
                                 ONAYLA
                            =============================== -->

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
                                    name="submission_id"
                                    value="<?= (int) $submission["id"] ?>"
                                >


                                <input
                                    type="hidden"
                                    name="action"
                                    value="approve"
                                >


                                <button
                                    type="submit"
                                    class="approve"
                                    onclick="return confirm('Bu çalışmayı onaylamak istediğinize emin misiniz?');"
                                >

                                    ✓ Onayla

                                </button>

                            </form>



                            <!-- ==============================
                                 REVİZE
                            =============================== -->

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
                                    name="submission_id"
                                    value="<?= (int) $submission["id"] ?>"
                                >


                                <input
                                    type="hidden"
                                    name="action"
                                    value="revise"
                                >


                                <button
                                    type="submit"
                                    class="revise"
                                    onclick="return confirm('Bu çalışma için revize istemek istediğinize emin misiniz?');"
                                >

                                    ↻ Revize İste

                                </button>

                            </form>


                        <?php endif; ?>



                        <!-- ==================================================
                             SİL
                        ================================================== -->

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
                                name="submission_id"
                                value="<?= (int) $submission["id"] ?>"
                            >


                            <input
                                type="hidden"
                                name="action"
                                value="delete"
                            >


                            <button
                                type="submit"
                                class="delete-button"
                                onclick="return confirm('Bu gönderiyi silmek istediğinize emin misiniz? Görevin kendisi silinmeyecektir.');"
                            >

                                🗑️ Gönderiyi Sil

                            </button>

                        </form>


                    </div>



                    <!-- ==================================================
                         ONAY MESAJI
                    ================================================== -->

                    <?php if (
                        $submission["status"]
                        === "onaylandı"
                    ): ?>

                        <div class="success submission-approved-message">

                            ✅

                            <strong>
                                Bu çalışma onaylandı.
                            </strong>

                        </div>

                    <?php endif; ?>


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

    document.body.classList.add("dark-mode");

    themeToggle.textContent = "☀️";

}


// Tema butonuna tıklanınca
themeToggle.addEventListener(
    "click",
    function () {

        document.body.classList.toggle(
            "dark-mode"
        );


        // Koyu mod
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


        // Açık mod
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