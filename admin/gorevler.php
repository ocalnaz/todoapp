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
// ADMİN KONTROLÜ
// ==================================================

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {

    echo "Bu siteye erişim yetkiniz yok.";
    exit;

}


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


// ==================================================
// KULLANICILARI GETİR
// ==================================================

$stmt = $db->query("
    SELECT
        id,
        username,
        full_name
    FROM users
    WHERE role = 'user'
    ORDER BY full_name
");

$users = $stmt->fetchAll(PDO::FETCH_ASSOC);


// ==================================================
// GÖREV SİLME
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

        $error =
            "Geçersiz istek. Lütfen sayfayı yenileyip tekrar deneyin.";

    } else {

        $delete_id = (int) $_POST["delete_id"];

        try {

            // Önce göreve ait gönderimleri sil
            $stmt = $db->prepare("
                DELETE FROM task_submissions
                WHERE task_id = ?
            ");

            $stmt->execute([
                $delete_id
            ]);


            // Daha sonra görevi sil
            $stmt = $db->prepare("
                DELETE FROM tasks
                WHERE id = ?
            ");

            $stmt->execute([
                $delete_id
            ]);


            header("Location: gorevler.php");
            exit;

        } catch (PDOException $e) {

            $error =
                "Görev silinirken hata oluştu.";
        }
    }
}


// ==================================================
// GÖREV OLUŞTURMA
// ==================================================

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["create_task"])
) {

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

        $title =
            trim($_POST["title"] ?? "");

        $description =
            trim($_POST["description"] ?? "");

        $assigned_to =
            $_POST["assigned_to"] ?? "";

        $due_date =
            $_POST["due_date"] ?? "";


        if (
            empty($title)
            || empty($description)
            || empty($assigned_to)
        ) {

            $error =
                "Lütfen gerekli alanları doldurun.";

        } else {

            try {

                $stmt = $db->prepare("
                    INSERT INTO tasks
                    (
                        title,
                        description,
                        assigned_to,
                        assigned_by,
                        due_date,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $title,
                    $description,
                    $assigned_to,
                    $_SESSION["user_id"],
                    $due_date,
                    "bekliyor"
                ]);


                // ==================================================
                // BİLDİRİM OLUŞTUR
                // ==================================================

                $notification_stmt = $db->prepare("
                    INSERT INTO notifications
                    (
                        user_id,
                        title,
                        message
                    )
                    VALUES (?, ?, ?)
                ");

                $notification_stmt->execute([
                    (int) $assigned_to,
                    "Yeni Görev Atandı",
                    "Size yeni bir görev atandı: " . $title
                ]);


                header("Location: gorevler.php");
                exit;

            } catch (PDOException $e) {

                $error =
                    "Görev oluşturulurken hata oluştu.";
            }
        }
    }
}


// ==================================================
// FİLTRELER
// ==================================================

$search =
    trim($_GET["search"] ?? "");

$filter_user =
    $_GET["filter_user"] ?? "";

$filter_status =
    $_GET["filter_status"] ?? "";

$filter_date_from =
    $_GET["date_from"] ?? "";

$filter_date_to =
    $_GET["date_to"] ?? "";

$filter_deadline =
    $_GET["deadline"] ?? "";

$quick_date =
    $_GET["quick_date"] ?? "";

$sort =
    $_GET["sort"] ?? "newest";


// ==================================================
// HIZLI TARİH FİLTRESİ
// ==================================================

if ($quick_date === "today") {

    $filter_date_from =
        date("Y-m-d");

    $filter_date_to =
        date("Y-m-d");

}

elseif ($quick_date === "week") {

    $filter_date_from =
        date(
            "Y-m-d",
            strtotime("monday this week")
        );

    $filter_date_to =
        date("Y-m-d");

}

elseif ($quick_date === "month") {

    $filter_date_from =
        date("Y-m-01");

    $filter_date_to =
        date("Y-m-d");
}


// ==================================================
// GÖREVLERİ GETİR
// ==================================================

$sql = "
    SELECT

        tasks.id,
        tasks.title,
        tasks.description,
        tasks.due_date,
        tasks.status,
        tasks.created_at,

        users.full_name,
        users.username

    FROM tasks

    INNER JOIN users
        ON tasks.assigned_to = users.id

    WHERE 1 = 1
";


$params = [];


// ==================================================
// GÖREV ADI / AÇIKLAMA ARAMA
// ==================================================

if ($search !== "") {

    $sql .= "
        AND (
            tasks.title LIKE ?
            OR tasks.description LIKE ?
        )
    ";

    $params[] =
        "%" . $search . "%";

    $params[] =
        "%" . $search . "%";
}


// ==================================================
// KULLANICI FİLTRESİ
// ==================================================

if ($filter_user !== "") {

    $sql .= "
        AND tasks.assigned_to = ?
    ";

    $params[] =
        (int) $filter_user;
}


// ==================================================
// DURUM FİLTRESİ
// ==================================================

if ($filter_status !== "") {

    $sql .= "
        AND tasks.status = ?
    ";

    $params[] =
        $filter_status;
}


// ==================================================
// BAŞLANGIÇ TARİHİ
// ==================================================

if ($filter_date_from !== "") {

    $sql .= "
        AND DATE(tasks.due_date) >= DATE(?)
    ";

    $params[] =
        $filter_date_from;
}


// ==================================================
// BİTİŞ TARİHİ
// ==================================================

if ($filter_date_to !== "") {

    $sql .= "
        AND DATE(tasks.due_date) <= DATE(?)
    ";

    $params[] =
        $filter_date_to;
}


// ==================================================
// SÜRE DURUMU
// ==================================================

if ($filter_deadline === "overdue") {

    $sql .= "
        AND tasks.due_date IS NOT NULL
        AND tasks.due_date != ''
        AND DATE(tasks.due_date) < DATE('now')
        AND tasks.status != 'onaylandı'
    ";

}

elseif ($filter_deadline === "not_overdue") {

    $sql .= "
        AND (
            tasks.due_date IS NULL
            OR tasks.due_date = ''
            OR DATE(tasks.due_date) >= DATE('now')
        )
    ";

}

elseif ($filter_deadline === "no_deadline") {

    $sql .= "
        AND (
            tasks.due_date IS NULL
            OR tasks.due_date = ''
        )
    ";
}


// ==================================================
// SIRALAMA
// ==================================================

switch ($sort) {

    case "oldest":

        $sql .= "
            ORDER BY tasks.created_at ASC
        ";

        break;


    case "deadline_soon":

        $sql .= "
            ORDER BY
                CASE
                    WHEN tasks.due_date IS NULL
                         OR tasks.due_date = ''
                    THEN 1
                    ELSE 0
                END,
                DATE(tasks.due_date) ASC
        ";

        break;


    case "deadline_late":

        $sql .= "
            ORDER BY
                CASE
                    WHEN tasks.due_date IS NULL
                         OR tasks.due_date = ''
                    THEN 1
                    ELSE 0
                END,
                DATE(tasks.due_date) DESC
        ";

        break;


    default:

        $sql .= "
            ORDER BY tasks.created_at DESC
        ";

        break;
}


// ==================================================
// SORGUYU ÇALIŞTIR
// ==================================================

$stmt =
    $db->prepare($sql);

$stmt->execute($params);

$tasks =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        Görev Yönetimi - Todo App
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
        Todo App
    </h2>


    <a href="dashboard.php">

        🏠 Dashboard

    </a>


    <a href="kullanicilar.php">

        🧒🏻👩🏻 Kullanıcılar

    </a>


    <a
        href="gorevler.php"
        class="active"
    >

        📒 Görevler

    </a>


    <a href="gonderilenler.php">

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

                Görev Yönetimi

            </h1>

            <p>

                Kullanıcılara görev atayın ve mevcut görevleri yönetin.

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
             YENİ GÖREV
        ================================================== -->

        <div class="box">

            <h2>

                📝 Yeni Görev Ata

            </h2>


            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        $_SESSION["csrf_token"]
                    ) ?>"
                >


                <label>

                    Görev Başlığı

                </label>


                <input
                    type="text"
                    name="title"
                    placeholder="Örneğin: Haftalık raporu hazırla"
                >


                <label>

                    Görev Açıklaması

                </label>


                <textarea
                    name="description"
                    placeholder="Görevin detaylarını yazın..."
                ></textarea>


                <label>

                    Görev Verilecek Kullanıcı

                </label>


                <select name="assigned_to">

                    <option value="">

                        Kullanıcı seçin

                    </option>


                    <?php foreach ($users as $user): ?>

                        <option
                            value="<?= (int) $user["id"] ?>"
                        >

                            <?= htmlspecialchars(
                                $user["full_name"]
                            ) ?>

                            (<?= htmlspecialchars(
                                $user["username"]
                            ) ?>)

                        </option>

                    <?php endforeach; ?>

                </select>


                <label>

                    Son Tarih

                </label>


                <input
                    type="date"
                    name="due_date"
                >


                <button
                    type="submit"
                    name="create_task"
                    value="1"
                    class="full-width"
                >

                    ➕ Görev Ata

                </button>

            </form>

        </div>


        <!-- ==================================================
             ATANAN GÖREVLER
        ================================================== -->

        <div class="box">

            <h2>

                📒 Atanan Görevler

            </h2>


            <!-- ==================================================
                 FİLTRELEME
            ================================================== -->

            <form
                method="GET"
                style="margin-bottom: 30px;"
            >

                <h3>

                    🔎 Görevleri Filtrele

                </h3>


                <label>

                    Görev Ara

                </label>


                <input
                    type="text"
                    name="search"
                    value="<?= htmlspecialchars($search) ?>"
                    placeholder="Görev adı veya açıklama..."
                >


                <label>

                    👤 Kullanıcı

                </label>


                <select name="filter_user">

                    <option value="">

                        👥 Tüm Kullanıcılar

                    </option>


                    <?php foreach ($users as $user): ?>

                        <option
                            value="<?= (int) $user["id"] ?>"
                            <?= (
                                $filter_user == $user["id"]
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


                <label>

                    📌 Durum

                </label>


                <select name="filter_status">

                    <option value="">

                        Tüm Durumlar

                    </option>


                    <option
                        value="bekliyor"
                        <?= $filter_status === "bekliyor"
                            ? "selected"
                            : "" ?>
                    >

                        ⏳ Bekliyor

                    </option>


                    <option
                        value="incelemede"
                        <?= $filter_status === "incelemede"
                            ? "selected"
                            : "" ?>
                    >

                        🔍 İncelemede

                    </option>


                    <option
                        value="revizyon"
                        <?= $filter_status === "revizyon"
                            ? "selected"
                            : "" ?>
                    >

                        🔄 Revizyon

                    </option>


                    <option
                        value="onaylandı"
                        <?= $filter_status === "onaylandı"
                            ? "selected"
                            : "" ?>
                    >

                        ✅ Onaylandı

                    </option>

                </select>


                <label>

                    ⚡ Hızlı Tarih

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


                <label>

                    📅 Son Tarih - Başlangıç

                </label>


                <input
                    type="date"
                    name="date_from"
                    value="<?= htmlspecialchars(
                        $filter_date_from
                    ) ?>"
                >


                <label>

                    📅 Son Tarih - Bitiş

                </label>


                <input
                    type="date"
                    name="date_to"
                    value="<?= htmlspecialchars(
                        $filter_date_to
                    ) ?>"
                >


                <label>

                    ⏰ Süre Durumu

                </label>


                <select name="deadline">

                    <option value="">

                        Tüm Süreler

                    </option>


                    <option
                        value="overdue"
                        <?= $filter_deadline === "overdue"
                            ? "selected"
                            : "" ?>
                    >

                        🔴 Süresi Geçenler

                    </option>


                    <option
                        value="not_overdue"
                        <?= $filter_deadline === "not_overdue"
                            ? "selected"
                            : "" ?>
                    >

                        🟢 Süresi Geçmeyenler

                    </option>


                    <option
                        value="no_deadline"
                        <?= $filter_deadline === "no_deadline"
                            ? "selected"
                            : "" ?>
                    >

                        ⚪ Son Tarihi Olmayanlar

                    </option>

                </select>


                <label>

                    ↕️ Sıralama

                </label>


                <select name="sort">

                    <option
                        value="newest"
                        <?= $sort === "newest"
                            ? "selected"
                            : "" ?>
                    >

                        🆕 En Yeni

                    </option>


                    <option
                        value="oldest"
                        <?= $sort === "oldest"
                            ? "selected"
                            : "" ?>
                    >

                        📜 En Eski

                    </option>


                    <option
                        value="deadline_soon"
                        <?= $sort === "deadline_soon"
                            ? "selected"
                            : "" ?>
                    >

                        ⏳ Son Tarihi Yaklaşan

                    </option>


                    <option
                        value="deadline_late"
                        <?= $sort === "deadline_late"
                            ? "selected"
                            : "" ?>
                    >

                        📅 Son Tarihi Uzak Olan

                    </option>

                </select>


                <!-- ==================================================
                     BUTONLAR
                ================================================== -->

                <div
                    style="
                        display:flex;
                        gap:10px;
                        margin-top:20px;
                    "
                >

                    <button
                        type="submit"
                    >

                        🔎 Filtrele

                    </button>


                    <a
                        href="gorevler.php"
                        class="edit-button"
                    >

                        🔄 Temizle

                    </a>

                </div>

            </form>


            <!-- ==================================================
                 FİLTRE SONUCU
            ================================================== -->

            <?php

            $has_filter =
                $search !== ""
                || $filter_user !== ""
                || $filter_status !== ""
                || $filter_date_from !== ""
                || $filter_date_to !== ""
                || $filter_deadline !== "";

            ?>


            <?php if ($has_filter): ?>

                <div class="info">

                    🔎 Filtre sonucunda

                    <strong>

                        <?= count($tasks) ?>

                    </strong>

                    görev bulundu.

                </div>

            <?php endif; ?>


            <!-- ==================================================
                 GÖREV LİSTESİ
            ================================================== -->

            <?php if (empty($tasks)): ?>


                <div class="empty">

                    <h2>

                        Görev bulunamadı.

                    </h2>

                    <p>

                        Seçtiğiniz filtrelere uygun görev bulunmuyor.

                    </p>

                </div>


            <?php else: ?>


                <table>

                    <tr>

                        <th>
                            ID
                        </th>

                        <th>
                            Görev
                        </th>

                        <th>
                            Kullanıcı
                        </th>

                        <th>
                            Son Tarih
                        </th>

                        <th>
                            Durum
                        </th>

                        <th>
                            İşlem
                        </th>

                    </tr>


                    <?php foreach ($tasks as $task): ?>


                        <?php

                        $is_overdue = false;


                        if (
                            !empty($task["due_date"])
                            && $task["due_date"] < date("Y-m-d")
                            && $task["status"] !== "onaylandı"
                        ) {

                            $is_overdue = true;
                        }


                        if (
                            $task["status"] === "bekliyor"
                        ) {

                            $statusClass = "bekliyor";

                        } elseif (
                            $task["status"] === "incelemede"
                        ) {

                            $statusClass = "incelemede";

                        } elseif (
                            $task["status"] === "revizyon"
                        ) {

                            $statusClass = "revize";

                        } elseif (
                            $task["status"] === "onaylandı"
                        ) {

                            $statusClass = "onaylandi";

                        } else {

                            $statusClass = "";
                        }

                        ?>


                        <tr>


                            <!-- ID -->

                            <td>

                                <?= (int) $task["id"] ?>

                            </td>


                            <!-- GÖREV -->

                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        $task["title"]
                                    ) ?>

                                </strong>


                                <br>
                                <br>


                                <?= nl2br(
                                    htmlspecialchars(
                                        $task["description"]
                                    )
                                ) ?>

                            </td>


                            <!-- KULLANICI -->

                            <td>

                                <?= htmlspecialchars(
                                    $task["full_name"]
                                ) ?>


                                <br>


                                <small>

                                    @<?= htmlspecialchars(
                                        $task["username"]
                                    ) ?>

                                </small>

                            </td>


                            <!-- SON TARİH -->

                            <td>

                                <?php if (
                                    !empty($task["due_date"])
                                ): ?>

                                    <?= htmlspecialchars(
                                        $task["due_date"]
                                    ) ?>

                                <?php else: ?>

                                    <span>

                                        —

                                    </span>

                                <?php endif; ?>


                                <?php if ($is_overdue): ?>

                                    <br>
                                    <br>

                                    <span
                                        class="status bekliyor"
                                    >

                                        🔴 Süresi Geçti

                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- DURUM -->

                            <td>

                                <span
                                    class="status <?= $statusClass ?>"
                                >

                                    <?php

                                    if (
                                        $task["status"] === "bekliyor"
                                    ) {

                                        echo "⏳ Bekliyor";

                                    } elseif (
                                        $task["status"] === "incelemede"
                                    ) {

                                        echo "🔍 İncelemede";

                                    } elseif (
                                        $task["status"] === "revizyon"
                                    ) {

                                        echo "🔄 Revizyon";

                                    } elseif (
                                        $task["status"] === "onaylandı"
                                    ) {

                                        echo "✅ Onaylandı";

                                    } else {

                                        echo htmlspecialchars(
                                            $task["status"]
                                        );
                                    }

                                    ?>

                                </span>

                            </td>


                            <!-- İŞLEM -->

                            <td>

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
                                        name="delete_id"
                                        value="<?= (int) $task["id"] ?>"
                                    >


                                    <button
                                        type="submit"
                                        name="delete_task"
                                        value="1"
                                        class="delete-button"
                                        onclick="
                                            return confirm(
                                                'Bu görevi silmek istediğinize emin misiniz?'
                                            );
                                        "
                                    >

                                        🗑️ Sil

                                    </button>

                                </form>

                            </td>


                        </tr>


                    <?php endforeach; ?>

                </table>


            <?php endif; ?>


        </div>


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
        'input[name="date_from"]'
    );

    const endDate = document.querySelector(
        'input[name="date_to"]'
    );


    const today = new Date();


    function formatDate(date) {

        const year =
            date.getFullYear();

        const month =
            String(
                date.getMonth() + 1
            ).padStart(2, "0");

        const day =
            String(
                date.getDate()
            ).padStart(2, "0");

        return `${year}-${month}-${day}`;
    }


    // BUGÜN
    if (value === "today") {

        const date =
            formatDate(today);

        startDate.value =
            date;

        endDate.value =
            date;
    }


    // BU HAFTA
    else if (value === "week") {

        const day =
            today.getDay();

        const monday =
            new Date(today);

        const diff =
            day === 0
                ? -6
                : 1 - day;


        monday.setDate(
            today.getDate() + diff
        );


        startDate.value =
            formatDate(monday);

        endDate.value =
            formatDate(today);
    }


    // BU AY
    else if (value === "month") {

        const firstDay =
            new Date(
                today.getFullYear(),
                today.getMonth(),
                1
            );


        startDate.value =
            formatDate(firstDay);

        endDate.value =
            formatDate(today);
    }


    // TEMİZLE
    else {

        startDate.value = "";

        endDate.value = "";
    }

}

</script>


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

</script>


</body>

</html>