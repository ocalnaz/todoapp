<?php
require_once __DIR__ . "/../config/session.php";

require_once "../config/database.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit;
}

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    http_response_code(403);
    echo "Bu sayfaya erişim yetkiniz yok.";
    exit;
}

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

$activityId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

if ($activityId === false || $activityId === null) {
    header("Location: dashboard.php#section-kullanici-calismalari");
    exit;
}

$stmt = $db->prepare("
    SELECT
        user_activities.id,
        user_activities.title,
        user_activities.description,
        user_activities.file_path,
        user_activities.created_at,
        users.full_name,
        users.username
    FROM user_activities
    INNER JOIN users
        ON user_activities.user_id = users.id
    WHERE user_activities.id = ?
    LIMIT 1
");

$stmt->execute([$activityId]);
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

    $uploadDirectory = realpath(
        __DIR__ . "/../uploads/activities"
    );

    $absoluteFilePath = $relativeFilePath === null
        ? false
        : realpath(
            __DIR__ . "/../" . $relativeFilePath
        );

    $hasValidFile =
        $uploadDirectory !== false &&
        $absoluteFilePath !== false &&
        is_file($absoluteFilePath) &&
        strpos(
            $absoluteFilePath,
            $uploadDirectory . DIRECTORY_SEPARATOR
        ) === 0;

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

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars(
            $pageTitle ?? "Dosya Görüntüleme",
            ENT_QUOTES,
            "UTF-8"
        ) ?>
    </title>

    <link
        rel="stylesheet"
        href="../assets/css/style.css?v=dosya-goruntule-v3"
    >


</head>

<body class="file-viewer-page">

    <main class="main file-viewer-main">

        <div class="container file-viewer-container">

        <div class="file-viewer-toolbar">

            <a
                class="file-viewer-button file-viewer-back-link"
                href="dashboard.php#section-kullanici-calismalari"
            >
                ← Çalışmalara Dön
            </a>

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

                <p class="file-viewer-notice">
                    <?= htmlspecialchars(
                        $pageError,
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>
                </p>

            <?php else: ?>

                <h1>📎 Dosya Görüntüleme</h1>

                <div class="file-viewer-metadata">

                    <div class="file-viewer-metadata-item">

                        <span class="file-viewer-metadata-label">
                            Görev / Çalışma
                        </span>

                        <strong>
                            <?= htmlspecialchars(
                                $activity["title"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </strong>

                    </div>

                    <div class="file-viewer-metadata-item">

                        <span class="file-viewer-metadata-label">
                            Kullanıcı
                        </span>

                        <strong>
                            <?= htmlspecialchars(
                                $activity["full_name"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </strong>

                        <div>
                            @<?= htmlspecialchars(
                                $activity["username"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </div>

                    </div>

                    <div class="file-viewer-metadata-item">

                        <span class="file-viewer-metadata-label">
                            Gönderim Tarihi
                        </span>

                        <strong>
                            <?= htmlspecialchars(
                                $activity["created_at"],
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </strong>

                    </div>

                </div>

                <?php if (!empty($activity["description"])): ?>

                    <p class="file-viewer-description">

                        <?= nl2br(
                            htmlspecialchars(
                                $activity["description"],
                                ENT_QUOTES,
                                "UTF-8"
                            )
                        ) ?>

                    </p>

                <?php endif; ?>

                <?php if (empty($hasValidFile)): ?>

                    <p class="file-viewer-notice">
                        Bu çalışmaya bağlı geçerli bir dosya bulunamadı
                        veya dosya yolu güvenli değil.
                    </p>

                <?php else: ?>

                    <section class="file-viewer-panel">

                        <div class="file-viewer-panel-header">

                            <strong>
                                📄
                                <?= htmlspecialchars(
                                    $fileName,
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>
                            </strong>

                            <a
                                class="file-viewer-button"
                                href="<?= htmlspecialchars(
                                    $publicFileUrl,
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Dosyayı Yeni Sekmede Aç
                            </a>

                        </div>

                        <?php if (
                            in_array(
                                $extension,
                                [
                                    "jpg",
                                    "jpeg",
                                    "png",
                                    "gif",
                                    "webp",
                                    "svg"
                                ],
                                true
                            )
                        ): ?>

                            <img
                                class="file-viewer-image-preview"
                                src="<?= htmlspecialchars(
                                    $publicFileUrl,
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                                alt="<?= htmlspecialchars(
                                    $fileName,
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                            >

                        <?php elseif ($extension === "pdf"): ?>

                            <iframe
                                class="file-viewer-preview"
                                src="<?= htmlspecialchars(
                                    $publicFileUrl,
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                                title="<?= htmlspecialchars(
                                    $fileName,
                                    ENT_QUOTES,
                                    "UTF-8"
                                ) ?>"
                            ></iframe>

                        <?php else: ?>

                            <div class="file-viewer-other-file">

                                <p>
                                    Bu dosya türü sayfa içinde
                                    önizlenemiyor.
                                </p>

                                <a
                                    class="file-viewer-button"
                                    href="<?= htmlspecialchars(
                                        $publicFileUrl,
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>"
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


    <!-- ==================================================
         🌙 TEMA JAVASCRIPT
    ================================================== -->

    <script>

        const themeToggle =
            document.getElementById("themeToggle");


        // Tema butonu varsa çalıştır
        if (themeToggle) {

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

        }

    </script>

</body>

</html>