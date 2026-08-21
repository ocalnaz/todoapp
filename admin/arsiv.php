<?php

declare(strict_types=1);

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/database.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if (($_SESSION["role"] ?? "") !== "admin") {
    http_response_code(403);
    echo "Bu siteye erişim yetkiniz yok.";
    exit;
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$admin_id = (int) $_SESSION["user_id"];
$message = "";
$error = "";

function archive_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}

function archive_delete_upload(string $relative_path): void
{
    $normalized = str_replace("\\", "/", trim($relative_path));

    if (
        $normalized === ""
        || strpos($normalized, "uploads/") !== 0
        || strpos($normalized, "..") !== false
    ) {
        return;
    }

    $project_root = realpath(__DIR__ . "/..");
    $upload_root = realpath(__DIR__ . "/../uploads");
    $candidate = realpath(__DIR__ . "/../" . $normalized);

    if (
        $project_root === false
        || $upload_root === false
        || $candidate === false
    ) {
        return;
    }

    $upload_prefix = rtrim($upload_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (
        strncmp($candidate, $upload_prefix, strlen($upload_prefix)) !== 0
        || !is_file($candidate)
    ) {
        return;
    }

    @unlink($candidate);
}

function archive_csrf_is_valid(): bool
{
    return isset($_POST["csrf_token"])
        && is_string($_POST["csrf_token"])
        && isset($_SESSION["csrf_token"])
        && hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"]);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!archive_csrf_is_valid()) {
        $error = "Geçersiz istek. Lütfen sayfayı yenileyip tekrar deneyin.";
    } else {
        $task_id = filter_var(
            $_POST["task_id"] ?? null,
            FILTER_VALIDATE_INT,
            ["options" => ["min_range" => 1]]
        );
        $action = $_POST["archive_action"] ?? "";

        if ($task_id === false || !in_array($action, ["restore", "permanent_delete"], true)) {
            $error = "Geçersiz arşiv işlemi.";
        } else {
            try {
                $task_stmt = $db->prepare(
                    "SELECT id, assigned_to, title
                     FROM tasks
                     WHERE id = ?
                       AND assigned_by = ?
                       AND deleted_at IS NOT NULL
                     LIMIT 1"
                );
                $task_stmt->execute([(int) $task_id, $admin_id]);
                $archived_task = $task_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$archived_task) {
                    $error = "Arşivlenmiş görev bulunamadı veya bu işlem için yetkiniz yok.";
                } elseif ($action === "restore") {
                    $restore_stmt = $db->prepare(
                        "UPDATE tasks
                         SET deleted_at = NULL,
                             deleted_by = NULL
                         WHERE id = ?
                           AND assigned_by = ?
                           AND deleted_at IS NOT NULL"
                    );
                    $restore_stmt->execute([(int) $task_id, $admin_id]);

                    if ((int) $restore_stmt->rowCount() === 1) {
                        $notification_stmt = $db->prepare(
                            "INSERT INTO notifications
                             (user_id, title, message, is_read, created_at)
                             VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP)"
                        );
                        $notification_stmt->execute([
                            (int) $archived_task["assigned_to"],
                            "Görev Geri Yüklendi",
                            "Arşivlenen göreviniz geri yüklendi: " . $archived_task["title"]
                        ]);
                        $message = "Görev başarıyla geri yüklendi.";
                    } else {
                        $error = "Görev geri yüklenemedi.";
                    }
                } else {
                    $db->beginTransaction();

                    $submission_stmt = $db->prepare(
                        "SELECT id, file_path
                         FROM task_submissions
                         WHERE task_id = ?"
                    );
                    $submission_stmt->execute([(int) $task_id]);
                    $submission_rows = $submission_stmt->fetchAll(PDO::FETCH_ASSOC);

                    $submission_ids = [];
                    foreach ($submission_rows as $submission_row) {
                        $submission_id = (int) ($submission_row["id"] ?? 0);
                        if ($submission_id > 0) {
                            $submission_ids[] = $submission_id;
                        }
                        archive_delete_upload((string) ($submission_row["file_path"] ?? ""));
                    }

                    if ($submission_ids !== []) {
                        $placeholders = implode(",", array_fill(0, count($submission_ids), "?"));
                        $file_stmt = $db->prepare(
                            "SELECT file_path
                             FROM task_submission_files
                             WHERE submission_id IN ($placeholders)"
                        );
                        $file_stmt->execute($submission_ids);
                        foreach ($file_stmt->fetchAll(PDO::FETCH_ASSOC) as $file_row) {
                            archive_delete_upload((string) ($file_row["file_path"] ?? ""));
                        }

                        $delete_files_stmt = $db->prepare(
                            "DELETE FROM task_submission_files
                             WHERE submission_id IN ($placeholders)"
                        );
                        $delete_files_stmt->execute($submission_ids);
                    }

                    $delete_submissions_stmt = $db->prepare(
                        "DELETE FROM task_submissions WHERE task_id = ?"
                    );
                    $delete_submissions_stmt->execute([(int) $task_id]);

                    $delete_daily_stmt = $db->prepare(
                        "DELETE FROM task_daily_tasks WHERE task_id = ?"
                    );
                    $delete_daily_stmt->execute([(int) $task_id]);

                    $delete_task_stmt = $db->prepare(
                        "DELETE FROM tasks
                         WHERE id = ?
                           AND assigned_by = ?
                           AND deleted_at IS NOT NULL"
                    );
                    $delete_task_stmt->execute([(int) $task_id, $admin_id]);

                    if ((int) $delete_task_stmt->rowCount() !== 1) {
                        throw new RuntimeException("Arşiv görevi silinemedi.");
                    }

                    $db->commit();
                    $message = "Görev ve bağlı gönderim dosyaları kalıcı olarak silindi.";
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log("Archive action failed: " . $e->getMessage());
                $error = "Arşiv işlemi sırasında bir hata oluştu.";
            }
        }
    }
}

try {
    $stmt = $db->prepare(
        "SELECT
            t.id,
            t.title,
            t.description,
            t.due_date,
            t.status,
            t.priority,
            t.created_at,
            t.deleted_at,
            u.full_name AS assigned_to_name,
            u.username AS assigned_to_username,
            deleted_user.full_name AS deleted_by_name,
            COUNT(DISTINCT ts.id) AS submission_count
         FROM tasks t
         LEFT JOIN users u ON t.assigned_to = u.id
         LEFT JOIN users deleted_user ON t.deleted_by = deleted_user.id
         LEFT JOIN task_submissions ts ON ts.task_id = t.id
         WHERE t.assigned_by = ?
           AND t.deleted_at IS NOT NULL
         GROUP BY t.id
         ORDER BY t.deleted_at DESC, t.id DESC"
    );
    $stmt->execute([$admin_id]);
    $archived_tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Archive list failed: " . $e->getMessage());
    $archived_tasks = [];
    $error = "Arşiv listesi yüklenirken bir hata oluştu.";
}

$priority_labels = [
    "urgent" => "Acil",
    "high" => "Yüksek",
    "normal" => "Normal",
    "low" => "Düşük"
];

$status_labels = [
    "bekliyor" => "Bekliyor",
    "devam ediyor" => "Devam ediyor",
    "tamamlandı" => "Tamamlandı",
    "incelemede" => "İncelemede",
    "revizyon" => "Revizyon",
    "onaylandı" => "Onaylandı"
];

$profile_image = null;
$unread_count = 0;
try {
    $profile_stmt = $db->prepare("SELECT profile_image FROM users WHERE id = ? LIMIT 1");
    $profile_stmt->execute([$admin_id]);
    $profile_data = $profile_stmt->fetch(PDO::FETCH_ASSOC);
    $profile_image = $profile_data["profile_image"] ?? null;

    $notification_count_stmt = $db->prepare(
        "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
    );
    $notification_count_stmt->execute([$admin_id]);
    $unread_count = (int) $notification_count_stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Archive sidebar data failed: " . $e->getMessage());
}

$profile_image_url = null;
$normalized_profile_path = str_replace("\\", "/", ltrim((string) ($profile_image ?? ""), "/"));
$profile_root = realpath(__DIR__ . "/../uploads/profile_images");
$profile_candidate = realpath(__DIR__ . "/../" . $normalized_profile_path);
if (
    $normalized_profile_path !== ""
    && strpos($normalized_profile_path, "..") === false
    && strpos($normalized_profile_path, "uploads/profile_images/") === 0
    && $profile_root !== false
    && $profile_candidate !== false
    && strpos($profile_candidate, $profile_root . DIRECTORY_SEPARATOR) === 0
) {
    $profile_image_url = "../" . $normalized_profile_path;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Görev Arşivi | Todo App</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="reference-admin-layout">
    <div class="archive-page">
        <div class="sidebar">
            <h2>TODO APP</h2>
            <div class="sidebar-profile-summary">
                <div class="sidebar-profile-avatar">
                    <?php if (!empty($profile_image_url)): ?>
                        <img src="<?= archive_h($profile_image_url) ?>" alt="Admin profil fotoğrafı">
                    <?php else: ?>
                        <span class="profile-placeholder" aria-hidden="true"></span>
                    <?php endif; ?>
                </div>
                <strong class="sidebar-profile-name"><?= archive_h((string) ($_SESSION["full_name"] ?? "Admin")) ?></strong>
                <span class="sidebar-profile-role">Yönetici</span>
            </div>

            <a href="dashboard.php#profil" class="menu-link">
                <span aria-hidden="true">👤</span> Profil
            </a>

            <nav class="sidebar-navigation" aria-label="Yönetici menüsü">
                <a href="dashboard.php#dashboard" class="menu-link"><span aria-hidden="true">📊</span> Dashboard</a>
                <a href="dashboard.php#raporlar" class="menu-link"><span aria-hidden="true">📊</span> Raporlar</a>
                <a href="raporlar.php" class="sidebar-page-link"><span aria-hidden="true">📈</span> Rapor Dışa Aktar</a>
                <a href="dashboard.php#kullanicilar" class="menu-link"><span aria-hidden="true">👥</span> Kullanıcılar</a>
                <a href="dashboard.php#gorev-ata" class="menu-link"><span aria-hidden="true">➕</span> Görev Ata</a>
                <a href="dashboard.php#gorevler" class="menu-link"><span aria-hidden="true">📋</span> Görevler</a>
                <a href="arsiv.php" class="sidebar-page-link active" aria-current="page"><span aria-hidden="true">🗃️</span> Görev Arşivi</a>
                <a href="dashboard.php#calismalar" class="menu-link"><span aria-hidden="true">📤</span> Görev Çalışmaları</a>
                <a href="dashboard.php#kullanici-calismalari" class="menu-link"><span aria-hidden="true">📝</span> Kullanıcı Çalışmaları</a>
                <a href="dashboard.php#bildirimler" class="menu-link">
                    <span aria-hidden="true">🔔</span> Bildirimler
                    <?php if ($unread_count > 0): ?>
                        <span class="notification-count sidebar-notification-count"><?= (int) $unread_count ?></span>
                    <?php endif; ?>
                </a>
                <a href="giris_loglari.php" class="sidebar-page-link"><span aria-hidden="true">🧾</span> Giriş Logları</a>
            </nav>

            <a href="../logout.php" class="logout"><span aria-hidden="true">🚪</span> Çıkış Yap</a>
        </div>

        <main class="main">
            <div class="container">
                <div class="section-title">
                    <h1>🗃️ Görev Arşivi</h1>
                    <p>Silinen görevleri geri yükleyin veya bağlı gönderimleriyle birlikte kalıcı olarak kaldırın.</p>
                </div>

            <?php if ($message !== ""): ?>
                <div class="alert success" role="status"><?= archive_h($message) ?></div>
            <?php endif; ?>
            <?php if ($error !== ""): ?>
                <div class="alert error" role="alert"><?= archive_h($error) ?></div>
            <?php endif; ?>

            <div class="box archive-card">
                <div class="section-heading-row">
                    <div>
                        <h2>Geri Dönüşüm Kutusu</h2>
                        <p>Arşivlenen görevler aktif panellerde görünmez, ancak gönderimleri korunur.</p>
                    </div>
                    <span class="archive-count"><?= count($archived_tasks) ?> görev</span>
                </div>

                <?php if ($archived_tasks === []): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">✓</div>
                        <h3>Arşiv boş</h3>
                        <p>Silinen görevler burada görünecek.</p>
                    </div>
                <?php else: ?>
                    <div class="archive-grid">
                        <?php foreach ($archived_tasks as $archived_task): ?>
                            <?php
                            $priority_key = (string) ($archived_task["priority"] ?? "normal");
                            $priority_label = $priority_labels[$priority_key] ?? "Normal";
                            $status_key = (string) ($archived_task["status"] ?? "");
                            $status_label = $status_labels[$status_key] ?? $status_key;
                            ?>
                            <article class="archive-item">
                                <div class="archive-item-topline">
                                    <span class="priority-badge priority-<?= archive_h($priority_key) ?>">
                                        <?= archive_h($priority_label) ?>
                                    </span>
                                    <span class="archive-date">
                                        <?= archive_h((string) ($archived_task["deleted_at"] ?? "")) ?>
                                    </span>
                                </div>
                                <h3><?= archive_h((string) ($archived_task["title"] ?? "İsimsiz görev")) ?></h3>
                                <p class="archive-description">
                                    <?= archive_h((string) ($archived_task["description"] ?? "Açıklama yok.")) ?>
                                </p>
                                <dl class="archive-meta">
                                    <div>
                                        <dt>Kullanıcı</dt>
                                        <dd><?= archive_h((string) ($archived_task["assigned_to_name"] ?? "Kullanıcı silinmiş")) ?></dd>
                                    </div>
                                    <div>
                                        <dt>Durum</dt>
                                        <dd><?= archive_h($status_label) ?></dd>
                                    </div>
                                    <div>
                                        <dt>Gönderim</dt>
                                        <dd><?= (int) ($archived_task["submission_count"] ?? 0) ?> kayıt</dd>
                                    </div>
                                </dl>
                                <p class="archive-deleted-by">
                                    Silen: <?= archive_h((string) ($archived_task["deleted_by_name"] ?? "Bilinmiyor")) ?>
                                </p>
                                <div class="archive-actions">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= archive_h($_SESSION["csrf_token"]) ?>">
                                        <input type="hidden" name="task_id" value="<?= (int) $archived_task["id"] ?>">
                                        <input type="hidden" name="archive_action" value="restore">
                                        <button type="submit" class="btn btn-primary">↩ Geri Yükle</button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Bu görev ve bağlı gönderim dosyaları kalıcı olarak silinecek. Devam edilsin mi?');">
                                        <input type="hidden" name="csrf_token" value="<?= archive_h($_SESSION["csrf_token"]) ?>">
                                        <input type="hidden" name="task_id" value="<?= (int) $archived_task["id"] ?>">
                                        <input type="hidden" name="archive_action" value="permanent_delete">
                                        <button type="submit" class="btn btn-danger">Kalıcı Sil</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            </div>
        </div>

        <button class="theme-toggle" id="themeToggle" title="Tema değiştir" type="button" aria-label="Tema değiştir">🌙</button>

        <script>
            const themeToggle = document.getElementById("themeToggle");
            if (localStorage.getItem("theme") === "dark") {
                document.body.classList.add("dark-mode");
                themeToggle.textContent = "☀️";
            }
            themeToggle.addEventListener("click", function () {
                document.body.classList.toggle("dark-mode");
                const isDark = document.body.classList.contains("dark-mode");
                themeToggle.textContent = isDark ? "☀️" : "🌙";
                localStorage.setItem("theme", isDark ? "dark" : "light");
            });
        </script>
</body>
</html>

