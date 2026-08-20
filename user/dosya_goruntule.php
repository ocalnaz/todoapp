
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
// USER KONTROLÜ
// ==================================================

if (
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "user"
) {
    http_response_code(403);
    echo "Bu sayfaya erişim yetkiniz yok.";
    exit;
}


$currentUserId = (int) $_SESSION["user_id"];


// ==================================================
// DOSYA YOLU GÜVENLİK KONTROLÜ
// ==================================================

function normalizeActivityUploadPath($filePath)
{
    if (!is_string($filePath)) {
        return null;
    }

    $relativePath = ltrim(
        str_replace("\\", "/", $filePath),
        "/"
    );

    if (
        $relativePath === "" ||
        strpos($relativePath, "uploads/activities/") !== 0 ||
        strpos($relativePath, "..") !== false ||
        strpos($relativePath, "\0") !== false
    ) {
        return null;
    }

    return $relativePath;
}


// ==================================================
// AKTİVİTE ID
// ==================================================

$activityId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT,
    [
        "options" => [
            "min_range" => 1
        ]
    ]
);


if (
    $activityId === false ||
    $activityId === null
) {
    header("Location: dashboard.php");
    exit;
}


// ==================================================
// ÇALIŞMA BİLGİLERİ
// ==================================================

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
      AND user_activities.user_id = ?
    LIMIT 1
");


$stmt->execute([
    $activityId,
    $currentUserId
]);


$activity = $stmt->fetch(
    PDO::FETCH_ASSOC
);


// ==================================================
// DOSYA KONTROLLERİ
// ==================================================

$hasValidFile = false;
$publicFileUrl = "";
$fileName = "";
$extension = "";


if (!$activity) {

    http_response_code(404);

    $pageTitle = "Çalışma bulunamadı";

    $pageError =
        "İstenen çalışma kaydı bulunamadı.";

} else {

    $pageTitle =
        "Dosya Görüntüleme";


    $relativeFilePath =
        normalizeActivityUploadPath(
            $activity["file_path"]
        );


    $uploadDirectory =
        realpath(
            __DIR__ .
            "/../uploads/activities"
        );


    $absoluteFilePath =
        $relativeFilePath === null
            ? false
            : realpath(
                __DIR__ .
                "/../" .
                $relativeFilePath
            );


    $hasValidFile =
        $uploadDirectory !== false &&
        $absoluteFilePath !== false &&
        is_file($absoluteFilePath) &&
        strpos(
            $absoluteFilePath,
            $uploadDirectory .
            DIRECTORY_SEPARATOR
        ) === 0;


    if ($hasValidFile) {

        $pathSegments =
            explode(
                "/",
                $relativeFilePath
            );


        $publicFileUrl =
            "../" .
            implode(
                "/",
                array_map(
                    "rawurlencode",
                    $pathSegments
                )
            );


        $fileName =
            basename(
                $relativeFilePath
            );


        $extension =
            strtolower(
                pathinfo(
                    $fileName,
                    PATHINFO_EXTENSION
                )
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


    <!-- ==================================================
         TEMA BUTONU
    ================================================== -->

    <button
        class="theme-toggle"
        id="themeToggle"
        title="Tema değiştir"
        type="button"
        aria-label="Tema değiştir"
    >
        🌙
    </button>


    <main class="main file-viewer-main">

        <div class="container file-viewer-container">


            <!-- ==================================================
                 ÜST BAR
            ================================================== -->

            <div class="file-viewer-toolbar">

                <a
                    class="file-viewer-button file-viewer-back-link"
                    href="dashboard.php"
                >
                    ← Dashboard'a Dön
                </a>

            </div>


            <!-- ==================================================
                 DOSYA KARTI
            ================================================== -->

            <section class="box file-viewer-card">


                <?php if (!$activity): ?>


                    <h1>
                        Çalışma bulunamadı
                    </h1>


                    <p class="file-viewer-notice">

                        <?= htmlspecialchars(
                            $pageError,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>

                    </p>


                <?php else: ?>


                    <h1>
                        📎 Dosya Görüntüleme
                    </h1>


                    <!-- ==================================================
                         DOSYA BİLGİLERİ
                    ================================================== -->

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


                    <!-- ==================================================
                         AÇIKLAMA
                    ================================================== -->

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


                    <!-- ==================================================
                         DOSYA YOK
                    ================================================== -->

                    <?php if (!$hasValidFile): ?>


                        <p class="file-viewer-notice">

                            Bu çalışmaya bağlı geçerli bir dosya
                            bulunamadı veya dosya yolu güvenli değil.

                        </p>


                    <?php else: ?>


                        <!-- ==================================================
                             DOSYA GÖRÜNTÜLEME PANELİ
                        ================================================== -->

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


                            <!-- ==================================================
                                 RESİM
                            ================================================== -->

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


                            <!-- ==================================================
                                 PDF
                            ================================================== -->

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


                            <!-- ==================================================
                                 DİĞER DOSYALAR
                            ================================================== -->

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
         TEMA JAVASCRIPT
    ================================================== -->

    <script>

        document.addEventListener(
            "DOMContentLoaded",
            function () {

                const themeToggle =
                    document.getElementById(
                        "themeToggle"
                    );


                /*
                 * Kayıtlı tema
                 */

                if (
                    localStorage.getItem(
                        "theme"
                    ) === "dark"
                ) {

                    document.body.classList.add(
                        "dark-mode"
                    );

                    if (themeToggle) {

                        themeToggle.textContent =
                            "☀️";

                    }

                } else {

                    if (themeToggle) {

                        themeToggle.textContent =
                            "🌙";

                    }

                }


                /*
                 * Tema değiştirme
                 */

                if (themeToggle) {

                    themeToggle.addEventListener(
                        "click",
                        function () {

                            document.body.classList.toggle(
                                "dark-mode"
                            );


                            const isDarkMode =
                                document.body.classList.contains(
                                    "dark-mode"
                                );


                            themeToggle.textContent =
                                isDarkMode
                                    ? "☀️"
                                    : "🌙";


                            localStorage.setItem(
                                "theme",
                                isDarkMode
                                    ? "dark"
                                    : "light"
                            );

                        }
                    );

                }

            }
        );

    </script>


</body>

</html>

