<?php

declare(strict_types=1);

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/report_data.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if (($_SESSION["role"] ?? "") !== "admin") {
    http_response_code(403);
    echo "Bu siteye erişim yetkiniz yok.";
    exit;
}

$admin_id = (int) $_SESSION["user_id"];
$filters = report_filters($_GET);
$tasks = [];
$users = [];
$error = "";

try {
    $tasks = report_fetch_tasks($db, $admin_id, $filters);

    $user_stmt = $db->query(
        "SELECT id, full_name, username
         FROM users
         WHERE role = 'user'
         ORDER BY full_name"
    );
    $users = $user_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Report page failed: " . $e->getMessage());
    $error = "Rapor verileri yüklenirken bir hata oluştu.";
}

$export_query = report_query($filters);
$export_suffix = $export_query === "" ? "" : "?" . $export_query;
$priority_labels = [
    "urgent" => "Acil",
    "high" => "Yüksek",
    "normal" => "Normal",
    "low" => "Düşük"
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
    error_log("Report sidebar data failed: " . $e->getMessage());
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
    <title>Raporlar | Todo App</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="reference-admin-layout">
    <div class="report-page">
        <div class="sidebar">
            <h2>TODO APP</h2>
            <div class="sidebar-profile-summary">
                <div class="sidebar-profile-avatar">
                    <?php if (!empty($profile_image_url)): ?>
                        <img src="<?= report_h($profile_image_url) ?>" alt="Admin profil fotoğrafı">
                    <?php else: ?>
                        <span class="profile-placeholder" aria-hidden="true"></span>
                    <?php endif; ?>
                </div>
                <strong class="sidebar-profile-name"><?= report_h((string) ($_SESSION["full_name"] ?? "Admin")) ?></strong>
                <span class="sidebar-profile-role">Yönetici</span>
            </div>

            <a href="dashboard.php#profil" class="menu-link">
                <span aria-hidden="true">👤</span> Profil
            </a>

            <nav class="sidebar-navigation" aria-label="Yönetici menüsü">
                <a href="dashboard.php#dashboard" class="menu-link"><span aria-hidden="true">📊</span> Dashboard</a>
                <a href="dashboard.php#raporlar" class="menu-link"><span aria-hidden="true">📊</span> Raporlar</a>
                <a href="raporlar.php" class="sidebar-page-link active" aria-current="page"><span aria-hidden="true">📈</span> Rapor Dışa Aktar</a>
                <a href="dashboard.php#kullanicilar" class="menu-link"><span aria-hidden="true">👥</span> Kullanıcılar</a>
                <a href="dashboard.php#gorev-ata" class="menu-link"><span aria-hidden="true">➕</span> Görev Ata</a>
                <a href="dashboard.php#gorevler" class="menu-link"><span aria-hidden="true">📋</span> Görevler</a>
                <a href="arsiv.php" class="sidebar-page-link"><span aria-hidden="true">🗃️</span> Görev Arşivi</a>
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
                    <h1>📈 Görev Raporları</h1>
                    <p>Görevleri filtreleyin ve CSV, Excel veya PDF formatında dışa aktarın.</p>
                </div>

            <?php if ($error !== ""): ?>
                <div class="alert error" role="alert"><?= report_h($error) ?></div>
            <?php endif; ?>

            <div class="box report-filter-card">
                <div class="section-heading-row">
                    <div>
                        <h2>Rapor Filtreleri</h2>
                        <p>Aktif görevler varsayılan olarak listelenir.</p>
                    </div>
                    <strong class="report-total-count"><?= count($tasks) ?> kayıt</strong>
                </div>

                <form method="get" class="report-filter-form">
                    <label>
                        Durum
                        <select name="status">
                            <option value="">Tüm durumlar</option>
                            <?php foreach (report_allowed_statuses() as $status): ?>
                                <option value="<?= report_h($status) ?>" <?= $filters["status"] === $status ? "selected" : "" ?>>
                                    <?= report_h(report_status_label($status)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Öncelik
                        <select name="priority">
                            <option value="">Tüm öncelikler</option>
                            <?php foreach ($priority_labels as $priority => $label): ?>
                                <option value="<?= report_h($priority) ?>" <?= $filters["priority"] === $priority ? "selected" : "" ?>>
                                    <?= report_h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Kullanıcı
                        <select name="user_id">
                            <option value="">Tüm kullanıcılar</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= (int) $user["id"] ?>" <?= $filters["user_id"] === (int) $user["id"] ? "selected" : "" ?>>
                                    <?= report_h((string) $user["full_name"]) ?> (@<?= report_h((string) $user["username"]) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="report-checkbox-label">
                        <input type="checkbox" name="include_archived" value="1" <?= $filters["include_archived"] ? "checked" : "" ?>>
                        Arşivlenmiş görevleri dahil et
                    </label>
                    <button type="submit" class="btn btn-primary">Filtrele</button>
                    <a href="raporlar.php" class="btn btn-secondary">Temizle</a>
                </form>
            </div>

            <div class="box report-export-card">
                <div class="section-heading-row">
                    <div>
                        <h2>Raporu Dışa Aktar</h2>
                        <p>Seçtiğiniz filtreler dışa aktarılan dosyaya uygulanır.</p>
                    </div>
                </div>
                <div class="report-export-actions">
                    <a class="report-export-button csv" href="report_export.php?format=csv<?= $export_suffix ?>">CSV indir</a>
                    <a class="report-export-button excel" href="report_export.php?format=xls<?= $export_suffix ?>">Excel indir</a>
                    <a class="report-export-button pdf" href="report_export.php?format=pdf<?= $export_suffix ?>">PDF indir</a>
                </div>
            </div>

            <div class="box report-table-card">
                <div class="table-responsive">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Görev</th>
                                <th>Kullanıcı</th>
                                <th>Öncelik</th>
                                <th>Durum</th>
                                <th>Son tarih</th>
                                <th>Gönderim</th>
                                <th>Arşiv</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($tasks === []): ?>
                            <tr>
                                <td colspan="8" class="table-empty">Filtrelere uyan görev bulunamadı.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($tasks as $task): ?>
                                <?php $priority = (string) ($task["priority"] ?? "normal"); ?>
                                <tr>
                                    <td>#<?= (int) $task["id"] ?></td>
                                    <td>
                                        <strong><?= report_h((string) ($task["title"] ?? "")) ?></strong>
                                        <?php if (($task["description"] ?? "") !== ""): ?>
                                            <small><?= report_h((string) $task["description"]) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= report_h((string) ($task["user_name"] ?? "Kullanıcı silinmiş")) ?></td>
                                    <td><span class="priority-badge priority-<?= report_h($priority) ?>"><?= report_h(report_priority_label($priority)) ?></span></td>
                                    <td><?= report_h(report_status_label((string) ($task["status"] ?? ""))) ?></td>
                                    <td><?= report_h((string) ($task["due_date"] ?? "-")) ?></td>
                                    <td><?= (int) ($task["submission_count"] ?? 0) ?></td>
                                    <td><?= $task["deleted_at"] === null ? "Aktif" : report_h((string) $task["deleted_at"]) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
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
