<?php

require_once __DIR__ . "/../config/session.php";

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

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    echo "Bu siteye erişim yetkiniz yok.";
    exit;
}


// ==================================================
// CSRF TOKEN
// ==================================================

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


$message = "";
$error = "";


// ==================================================
// YÜKLEME DOSYASI YOLU DOĞRULAMA
// ==================================================

function normalizeUploadPath($filePath)
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
// ÇALIŞMA SİLME
// ==================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["delete_id"])
) {

    if (
        !isset($_POST["csrf_token"])
        || !hash_equals(
            $_SESSION["csrf_token"],
            $_POST["csrf_token"]
        )
    ) {

        $error = "Geçersiz istek. Lütfen sayfayı yenileyip tekrar deneyin.";

    } else {

        $delete_id = (int) $_POST["delete_id"];

        try {

            // Silinecek çalışmanın dosya yolunu al
            $stmt = $db->prepare("
                SELECT file_path
                FROM user_activities
                WHERE id = ?
            ");

            $stmt->execute([$delete_id]);

            $activity = $stmt->fetch(PDO::FETCH_ASSOC);


            if (!$activity) {

                $error = "Silinecek çalışma bulunamadı.";

            } else {

                // ==================================================
                // DOSYAYI FİZİKSEL OLARAK SİL
                // ==================================================

                $relativeFilePath = normalizeUploadPath(
                    $activity["file_path"]
                );

                if (
                    !empty($activity["file_path"]) &&
                    $relativeFilePath === null
                ) {

                    $error = "Geçersiz dosya yolu nedeniyle çalışma silinemedi.";

                } else {

                    $uploadDirectory = realpath(
                                                __DIR__ . "/../uploads/activities"

                    );

                    $file = $relativeFilePath === null
                        ? false
                        : realpath(
                            __DIR__ . "/../" . $relativeFilePath
                        );

                    $hasValidFilePath =
                        $file === false ||
                        (
                            $uploadDirectory !== false &&
                            is_file($file) &&
                            strpos(
                                $file,
                                $uploadDirectory . DIRECTORY_SEPARATOR
                            ) === 0
                        );

                    if (!$hasValidFilePath) {

                        $error =
                            "Dosya yolu doğrulanamadığı için çalışma silinemedi.";

                    } elseif ($file !== false && !unlink($file)) {

                        $error =
                            "Ekli dosya silinemediği için çalışma silinmedi.";

                    } else {

                        // ==================================================
                        // VERİTABANINDAN ÇALIŞMAYI SİL
                        // ==================================================

                        $stmt = $db->prepare("
                            DELETE FROM user_activities
                            WHERE id = ?
                        ");

                        $stmt->execute([$delete_id]);

                        $message = "Çalışma başarıyla silindi.";
                    }
                }
            }

        } catch (PDOException $e) {

            $error = "Çalışma silinirken bir hata oluştu.";
        }
    }
}


// ==================================================
// FİLTRE DEĞERLERİ
// ==================================================

$search = trim($_GET["search"] ?? "");

$user_filter = $_GET["user_filter"] ?? "";

$start_date = $_GET["start_date"] ?? "";

$end_date = $_GET["end_date"] ?? "";

$file_filter = $_GET["file_filter"] ?? "";

// Dosya adında arama yapmak için kullanılır.
$file_name = trim($_GET["file_name"] ?? "");

$quick_date = $_GET["quick_date"] ?? "";


// ==================================================
// HIZLI TARİH FİLTRESİ
// ==================================================

if ($quick_date === "today") {

    $start_date = date("Y-m-d");
    $end_date = date("Y-m-d");

}

elseif ($quick_date === "week") {

    $start_date = date(
        "Y-m-d",
        strtotime("monday this week")
    );

    $end_date = date("Y-m-d");

}

elseif ($quick_date === "month") {

    $start_date = date("Y-m-01");

    $end_date = date("Y-m-d");
}


// ==================================================
// KULLANICILARI GETİR
// ==================================================

$stmt = $db->query("
    SELECT
        id,
        full_name,
        username
    FROM users
    WHERE role = 'user'
    ORDER BY full_name ASC
");

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ==================================================
// FİLTRELİ ÇALIŞMALARI GETİR
// ==================================================

$sql = "
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

    WHERE 1=1
";


$params = [];


// ==================================================
// ÇALIŞMA ADI / AÇIKLAMA ARAMA
// ==================================================

if ($search !== "") {

    $sql .= "
        AND (
            user_activities.title LIKE ?
            OR user_activities.description LIKE ?
        )
    ";

    $params[] = "%" . $search . "%";
    $params[] = "%" . $search . "%";
}


// ==================================================
// DOSYA ADI FİLTRESİ
// ==================================================

if ($file_name !== "") {

    $sql .= "
        AND user_activities.file_path LIKE ?
    ";

    $params[] = "%" . $file_name . "%";
}


// ==================================================
// KULLANICI FİLTRESİ
// ==================================================

if ($user_filter !== "") {

    $sql .= "
        AND user_activities.user_id = ?
    ";

    $params[] = (int) $user_filter;
}


// ==================================================
// BAŞLANGIÇ TARİHİ FİLTRESİ
// ==================================================

if ($start_date !== "") {

    $sql .= "
        AND DATE(user_activities.created_at) >= DATE(?)
    ";

    $params[] = $start_date;
}


// ==================================================
// BİTİŞ TARİHİ FİLTRESİ
// ==================================================

if ($end_date !== "") {

    $sql .= "
        AND DATE(user_activities.created_at) <= DATE(?)
    ";

    $params[] = $end_date;
}


// ==================================================
// DOSYA FİLTRESİ
// ==================================================

if ($file_filter === "with_file") {

    $sql .= "
        AND user_activities.file_path IS NOT NULL
        AND user_activities.file_path != ''
    ";

}

elseif ($file_filter === "without_file") {

    $sql .= "
        AND (
            user_activities.file_path IS NULL
            OR user_activities.file_path = ''
        )
    ";
}


// ==================================================
// SIRALAMA
// ==================================================

$sql .= "
    ORDER BY user_activities.created_at DESC
";


// ==================================================
// SORGUYU ÇALIŞTIR
// ==================================================

$stmt = $db->prepare($sql);

$stmt->execute($params);

$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>

<html lang="tr">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Kullanıcı Çalışmaları - Todo App</title>

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

    <h2>TODO APP</h2>


    <a href="dashboard.php">
        🏠 Dashboard
    </a>


    <a href="kullanicilar.php">
        🧒🏻👩🏻 Kullanıcılar
    </a>


    <a href="gorevler.php">
        📒 Görevler
    </a>


    <a href="gonderilenler.php">
        📤 Gönderilenler
    </a>


    <a
        href="calismalar.php"
        class="active"
    >
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
                Kullanıcı Çalışmaları
            </h1>

            <p>
                Kullanıcıların eklediği çalışmalar ve görev teslimleri burada görüntülenir.
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
             BÖLÜM BAŞLIĞI
        ================================================== -->

        <h2 style="margin-top: 30px;">

            📝 Kullanıcıların Eklediği Çalışmalar

        </h2>


        <p style="margin-bottom: 25px;">

            Kullanıcıların sisteme doğrudan eklediği çalışmalar.

        </p>


        <!-- ==================================================
             FİLTRELEME
        ================================================== -->

        <div class="box">

            <h2>
                🔎 Çalışmaları Filtrele
            </h2>


            <form method="GET">


                <!-- ÇALIŞMA ARAMA -->

                <label>
                    Çalışma Ara
                </label>


                <input
                    type="text"
                    name="search"
                    placeholder="Çalışma başlığı veya açıklama..."
                    value="<?= htmlspecialchars($search) ?>"
                >


                <!-- DOSYA ADI ARAMA -->

                <label>
                    Dosya Adı Ara
                </label>

                <input
                    type="search"
                    name="file_name"
                    placeholder="Dosya adının tamamı veya bir bölümü..."
                    value="<?= htmlspecialchars($file_name) ?>"
                >


                <!-- KULLANICI -->

                <label>
                    Kullanıcı
                </label>


                <select name="user_filter">

                    <option value="">
                        👥 Tüm Kullanıcılar
                    </option>


                    <?php foreach ($users as $user): ?>

                        <option
                            value="<?= (int) $user["id"] ?>"
                            <?= (
                                $user_filter == $user["id"]
                            ) ? "selected" : "" ?>
                        >

                            <?= htmlspecialchars(
                                $user["full_name"]
                            ) ?>

                            (@<?= htmlspecialchars(
                                $user["username"]
                            ) ?>)

                        </option>

                    <?php endforeach; ?>

                </select>


                <!-- HIZLI TARİH -->

                <label>
                    Hızlı Tarih Seç
                </label>


                <select
                    name="quick_date"
                    onchange="setQuickDate(this.value)"
                >

                    <option value="">
                        📅 Tarih seçin
                    </option>


                    <option
                        value="today"
                        <?= $quick_date === "today"
                            ? "selected"
                            : "" ?>
                    >
                        📌 Bugün
                    </option>


                    <option
                        value="week"
                        <?= $quick_date === "week"
                            ? "selected"
                            : "" ?>
                    >
                        📆 Bu Hafta
                    </option>


                    <option
                        value="month"
                        <?= $quick_date === "month"
                            ? "selected"
                            : "" ?>
                    >
                        🗓️ Bu Ay
                    </option>

                </select>


                <!-- BAŞLANGIÇ TARİHİ -->

                <label>
                    Başlangıç Tarihi
                </label>


                <input
                    type="date"
                    name="start_date"
                    value="<?= htmlspecialchars($start_date) ?>"
                >


                <!-- BİTİŞ TARİHİ -->

                <label>
                    Bitiş Tarihi
                </label>


                <input
                    type="date"
                    name="end_date"
                    value="<?= htmlspecialchars($end_date) ?>"
                >


                <!-- DOSYA FİLTRESİ -->

                <label>
                    Dosya Durumu
                </label>


                <select name="file_filter">

                    <option
                        value=""
                        <?= $file_filter === ""
                            ? "selected"
                            : "" ?>
                    >
                        📁 Tüm Çalışmalar
                    </option>


                    <option
                        value="with_file"
                        <?= $file_filter === "with_file"
                            ? "selected"
                            : "" ?>
                    >
                        📎 Dosyası Olanlar
                    </option>


                    <option
                        value="without_file"
                        <?= $file_filter === "without_file"
                            ? "selected"
                            : "" ?>
                    >
                        📄 Dosyası Olmayanlar
                    </option>

                </select>


                <!-- BUTONLAR -->

                <div
                    style="
                        display: flex;
                        gap: 10px;
                        margin-top: 20px;
                    "
                >

                    <button type="submit">
                        🔎 Filtrele
                    </button>


                    <a
                        href="calismalar.php"
                        class="edit-button"
                    >
                        🔄 Temizle
                    </a>

                </div>


            </form>

        </div>


        <!-- ==================================================
             ÇALIŞMALAR
        ================================================== -->

        <?php if (empty($activities)): ?>


            <div class="empty">

                <h2>
                    Henüz çalışma bulunmuyor.
                </h2>

                <p>
                    Seçtiğiniz filtrelere uygun çalışma bulunamadı.
                </p>

            </div>


        <?php else: ?>


            <?php foreach ($activities as $activity): ?>

                <?php
                    $activityFilePath = normalizeUploadPath(
                        $activity["file_path"]
                    );
                ?>

                <div class="box submission-card">


                    <!-- BAŞLIK -->

                    <h2>

                        📌

                        <?= htmlspecialchars(
                            $activity["title"]
                        ) ?>

                    </h2>


                    <!-- KULLANICI -->

                    <div class="user-info">

                        👤

                        <strong>

                            <?= htmlspecialchars(
                                $activity["full_name"]
                            ) ?>

                        </strong>

                        (@<?= htmlspecialchars(
                            $activity["username"]
                        ) ?>)

                    </div>


                    <!-- AÇIKLAMA -->

                    <div class="work">

                        <?= nl2br(
                            htmlspecialchars(
                                $activity["description"]
                            )
                        ) ?>

                    </div>


                    <!-- ==================================================
                         DOSYA EKİ
                    ================================================== -->

                    <div class="file-box">

                        <strong>
                            📎 Dosya Eki
                        </strong>


                        <?php if ($activityFilePath !== null): ?>


                            <div style="margin-top: 12px;">

                                <span>

                                    📄

                                    <?= htmlspecialchars(
                                        basename($activityFilePath),
                                        ENT_QUOTES,
                                        "UTF-8"
                                    ) ?>

                                </span>


                                <br><br>


                                <a
                                    href="dosya_goruntule.php?id=<?= (int) $activity["id"] ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="edit-button"
                                >

                                    👁️ Dosyayı Görüntüle

                                </a>

                            </div>


                        <?php else: ?>


                            <p style="margin-top: 10px;">

                                <?= !empty($activity["file_path"])
                                    ? "Dosya eki geçersiz veya güvenli değil."
                                    : "Bu gönderide dosya eki bulunmuyor." ?>

                            </p>


                        <?php endif; ?>

                    </div>


                    <!-- TARİH -->

                    <div class="date">

                        📅

                        <?= htmlspecialchars(
                            $activity["created_at"]
                        ) ?>

                    </div>


                    <!-- SİL -->

                    <form
                        method="POST"
                        style="margin-top: 20px;"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(
                                $_SESSION["csrf_token"]
                            ) ?>"
                        >


                        <input
                            type="hidden"
                            name="delete_id"
                            value="<?= (int) $activity["id"] ?>"
                        >


                        <button
                            type="submit"
                            class="delete-button"
                            onclick="return confirm('Bu çalışmayı ve ekli dosyasını silmek istediğinize emin misiniz?');"
                        >

                            🗑️ Çalışmayı Sil

                        </button>

                    </form>


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
     HIZLI TARİH JAVASCRIPT
================================================== -->

<script>

function setQuickDate(value) {

    const startDate = document.querySelector(
        'input[name="start_date"]'
    );

    const endDate = document.querySelector(
        'input[name="end_date"]'
    );


    const today = new Date();


    function formatDate(date) {

        const year = date.getFullYear();

        const month = String(
            date.getMonth() + 1
        ).padStart(2, "0");

        const day = String(
            date.getDate()
        ).padStart(2, "0");

        return `${year}-${month}-${day}`;
    }


    // BUGÜN

    if (value === "today") {

        const date = formatDate(today);

        startDate.value = date;

        endDate.value = date;
    }


    // BU HAFTA

    else if (value === "week") {

        const day = today.getDay();

        const monday = new Date(today);

        const diff = day === 0
            ? -6
            : 1 - day;


        monday.setDate(
            today.getDate() + diff
        );


        startDate.value = formatDate(monday);

        endDate.value = formatDate(today);
    }


    // BU AY

    else if (value === "month") {

        const firstDay = new Date(
            today.getFullYear(),
            today.getMonth(),
            1
        );


        startDate.value = formatDate(firstDay);

        endDate.value = formatDate(today);
    }


    // TEMİZLE

    else {

        startDate.value = "";

        endDate.value = "";
    }

}


// ==================================================
// 🌙 TEMA SİSTEMİ
// ==================================================

const themeToggle = document.getElementById("themeToggle");


// Daha önce seçilen temayı kontrol et

if (localStorage.getItem("theme") === "dark") {

    document.body.classList.add("dark-mode");

    themeToggle.textContent = "☀️";

}


// Tema değiştirme

themeToggle.addEventListener("click", function () {

    document.body.classList.toggle("dark-mode");


    if (document.body.classList.contains("dark-mode")) {

        themeToggle.textContent = "☀️";

        localStorage.setItem("theme", "dark");

    } else {

        themeToggle.textContent = "🌙";

        localStorage.setItem("theme", "light");

    }

});

</script>


</body>

</html>