<?php
require_once __DIR__ . "/../config/session.php";

require_once "../config/database.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "user") {
    http_response_code(403);
    echo "Bu sayfaya erişim yetkiniz yok.";
    exit;
}

$currentUserId = (int) $_SESSION["user_id"];

function normalizeActivityUploadPath($filePath)
{
    if (!is_string($filePath)) {
        return null;
    }

    $storedPath = trim(str_replace("\\", "/", $filePath));

    if (
        $storedPath === "" ||
        strpos($storedPath, "\0") !== false ||
        strpos($storedPath, "://") !== false ||
        strpos($storedPath, "//") === 0
    ) {
        return null;
    }

    // Eski kayıtlarda başta ../ veya / bulunabiliyor.
    $storedPath = ltrim($storedPath, "/");
    while (strpos($storedPath, "../") === 0) {
        $storedPath = substr($storedPath, 3);
    }

    if ($storedPath === "" || strpos($storedPath, "..") !== false) {
        return null;
    }

    if (strpos($storedPath, "uploads/activities/") === 0) {
        return $storedPath;
    }

    // Bazı eski kayıtlarda yalnızca dosya adı tutulmuş olabilir.
    $fileName = basename($storedPath);

    if (
        $fileName === "" ||
        $fileName === "." ||
        $fileName === ".." ||
        !preg_match('/^[A-Za-z0-9._-]+$/D', $fileName)
    ) {
        return null;
    }

    return "uploads/activities/" . $fileName;
}

function format_turkey_datetime($value): string
{
    $value = trim((string) $value);

    if ($value === "") {
        return "-";
    }

    try {
        $utcDate = new DateTimeImmutable(
            $value,
            new DateTimeZone("UTC")
        );

        return $utcDate
            ->setTimezone(new DateTimeZone("Europe/Istanbul"))
            ->format("Y-m-d H:i:s");
    } catch (Exception $e) {
        return $value;
    }
}

$activityId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

if ($activityId === false || $activityId === null) {
    header("Location: dashboard.php");
    exit;
}

$stmt = $db->prepare("\n    SELECT\n        user_activities.id,\n        user_activities.title,\n        user_activities.description,\n        user_activities.file_path,\n        user_activities.created_at,\n        users.full_name,\n        users.username\n    FROM user_activities\n    INNER JOIN users\n        ON user_activities.user_id = users.id\n    WHERE user_activities.id = ?\n      AND user_activities.user_id = ?\n    LIMIT 1\n");

$stmt->execute([$activityId, $currentUserId]);
$activity = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$activity) {
    http_response_code(404);
    $pageTitle = "Çalışma bulunamadı";
    $pageError = "İstenen çalışma kaydı bulunamadı.";
} else {
    $pageTitle = "Dosya Görüntüleme";
    $relativeFilePath = normalizeActivityUploadPath(
        $activity["file_path"]
    );

    $uploadDirectory = realpath(__DIR__ . "/../uploads/activities");
    $absoluteFilePath = false;
    $hasValidFile = false;

    if ($relativeFilePath !== null && $uploadDirectory !== false) {
        $absoluteFilePath = realpath(
            __DIR__ . "/../" . str_replace(
                "/",
                DIRECTORY_SEPARATOR,
                $relativeFilePath
            )
        );

        $hasValidFile =
            $absoluteFilePath !== false &&
            is_file($absoluteFilePath) &&
            (
                $absoluteFilePath === $uploadDirectory ||
                strpos(
                    $absoluteFilePath,
                    $uploadDirectory . DIRECTORY_SEPARATOR
                ) === 0
            );
    }

    if ($hasValidFile) {
        $pathSegments = explode("/", $relativeFilePath);
        $publicFileUrl = "../" . implode(
            "/",
            array_map("rawurlencode", $pathSegments)
        );

        $fileName = basename($relativeFilePath);
        $extension = strtolower(
            pathinfo($fileName, PATHINFO_EXTENSION)
        );
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? "Dosya Görüntüleme", ENT_QUOTES, "UTF-8") ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=dosya-goruntule-v3">
</head>
<body class="file-viewer-page">
    <main class="main file-viewer-main">
        <div class="container file-viewer-container">
        <div class="file-viewer-toolbar">
            <a class="file-viewer-button file-viewer-back-link" href="dashboard.php">← Dashboard'a Dön</a>
            <button
                type="button"
                class="theme-toggle file-viewer-theme-toggle"
                id="themeToggle"
                aria-label="Temayı değiştir"
                title="Temayı değiştir"
            >
                🌙
            </button>
        </div>

        <section class="box file-viewer-card">
            <?php if (!$activity): ?>
                <h1>Çalışma bulunamadı</h1>
                <p class="file-viewer-notice"><?= htmlspecialchars($pageError, ENT_QUOTES, "UTF-8") ?></p>
            <?php else: ?>
                <h1>📎 Dosya Görüntüleme</h1>

                <div class="file-viewer-metadata">
                    <div class="file-viewer-metadata-item">
                        <span class="file-viewer-metadata-label">Görev / Çalışma</span>
                        <strong><?= htmlspecialchars($activity["title"], ENT_QUOTES, "UTF-8") ?></strong>
                    </div>
                    <div class="file-viewer-metadata-item">
                        <span class="file-viewer-metadata-label">Kullanıcı</span>
                        <strong><?= htmlspecialchars($activity["full_name"], ENT_QUOTES, "UTF-8") ?></strong>
                        <div>@<?= htmlspecialchars($activity["username"], ENT_QUOTES, "UTF-8") ?></div>
                    </div>
                    <div class="file-viewer-metadata-item">
                        <span class="file-viewer-metadata-label">Gönderim Tarihi</span>
                        <strong><?= htmlspecialchars(
                            format_turkey_datetime($activity["created_at"]),
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?></strong>
                    </div>
                </div>

                <?php if (!empty($activity["description"])): ?>
                    <p class="file-viewer-description">
                        <?= nl2br(htmlspecialchars($activity["description"], ENT_QUOTES, "UTF-8")) ?>
                    </p>
                <?php endif; ?>

                <?php if (empty($hasValidFile)): ?>
                    <p class="file-viewer-notice">
                        Bu çalışmaya bağlı geçerli bir dosya bulunamadı veya dosya yolu güvenli değil.
                    </p>
                <?php else: ?>
                    <section class="file-viewer-panel">
                        <div class="file-viewer-panel-header">
                            <strong>📄 <?= htmlspecialchars($fileName, ENT_QUOTES, "UTF-8") ?></strong>
                            <a
                                class="file-viewer-button"
                                href="<?= htmlspecialchars($publicFileUrl, ENT_QUOTES, "UTF-8") ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Dosyayı Yeni Sekmede Aç
                            </a>
                        </div>

                        <?php if (in_array($extension, ["jpg", "jpeg", "png", "gif", "webp", "svg"], true)): ?>
                            <img
                                class="file-viewer-image-preview"
                                src="<?= htmlspecialchars($publicFileUrl, ENT_QUOTES, "UTF-8") ?>"
                                alt="<?= htmlspecialchars($fileName, ENT_QUOTES, "UTF-8") ?>"
                            >
                        <?php elseif ($extension === "pdf"): ?>
                            <iframe
                                class="file-viewer-preview"
                                src="<?= htmlspecialchars($publicFileUrl, ENT_QUOTES, "UTF-8") ?>"
                                title="<?= htmlspecialchars($fileName, ENT_QUOTES, "UTF-8") ?>"
                            ></iframe>
                        <?php else: ?>
                            <div class="file-viewer-other-file">
                                <p>Bu dosya türü sayfa içinde önizlenemiyor.</p>
                                <a
                                    class="file-viewer-button"
                                    href="<?= htmlspecialchars($publicFileUrl, ENT_QUOTES, "UTF-8") ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Dosyayı Aç veya İndir
                                </a>
                            </div>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        </div>
    </main>

    <script>
        (() => {
            const themeToggle = document.getElementById("themeToggle");
            const savedTheme = localStorage.getItem("theme");

            if (savedTheme === "dark") {
                document.body.classList.add("dark-mode");
            }

            const syncThemeButton = () => {
                const isDark = document.body.classList.contains("dark-mode");
                if (themeToggle) {
                    themeToggle.textContent = isDark ? "☀️" : "🌙";
                    themeToggle.setAttribute(
                        "aria-label",
                        isDark ? "Açık temaya geç" : "Koyu temaya geç"
                    );
                    themeToggle.setAttribute(
                        "title",
                        isDark ? "Açık temaya geç" : "Koyu temaya geç"
                    );
                }
            };

            syncThemeButton();

            if (themeToggle) {
                themeToggle.addEventListener("click", () => {
                    const isDark = document.body.classList.toggle("dark-mode");
                    localStorage.setItem("theme", isDark ? "dark" : "light");
                    syncThemeButton();
                });
            }
        })();
    </script>
</body>
</html>
