<?php

declare(strict_types=1);

require_once __DIR__ . "/../config/session.php";
require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/report_data.php";

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Oturum gerekli.");
}

if (($_SESSION["role"] ?? "") !== "admin") {
    http_response_code(403);
    exit("Bu raporu görüntüleme yetkiniz yok.");
}

$format = strtolower((string) ($_GET["format"] ?? "csv"));
if (!in_array($format, ["csv", "xls", "pdf"], true)) {
    http_response_code(400);
    exit("Geçersiz rapor formatı.");
}

$filters = report_filters($_GET);
$tasks = report_fetch_tasks($db, (int) $_SESSION["user_id"], $filters);
$stamp = date("Y-m-d_H-i-s");
$filter_query = report_query($filters);

$columns = [
    "ID",
    "Görev",
    "Açıklama",
    "Kullanıcı",
    "Kullanıcı Adı",
    "Atayan Yönetici",
    "Öncelik",
    "Durum",
    "Son Tarih",
    "Oluşturulma",
    "Arşivlenme",
    "Gönderim Sayısı"
];

function report_cell(array $task, string $key): string
{
    return match ($key) {
        "ID" => (string) ((int) ($task["id"] ?? 0)),
        "Görev" => (string) ($task["title"] ?? ""),
        "Açıklama" => (string) ($task["description"] ?? ""),
        "Kullanıcı" => (string) ($task["user_name"] ?? "Kullanıcı silinmiş"),
        "Kullanıcı Adı" => (string) ($task["username"] ?? ""),
        "Atayan Yönetici" => (string) ($task["assigned_by_name"] ?? ""),
        "Öncelik" => report_priority_label((string) ($task["priority"] ?? "normal")),
        "Durum" => report_status_label((string) ($task["status"] ?? "")),
        "Son Tarih" => (string) ($task["due_date"] ?? ""),
        "Oluşturulma" => (string) ($task["created_at"] ?? ""),
        "Arşivlenme" => (string) ($task["deleted_at"] ?? "Aktif"),
        "Gönderim Sayısı" => (string) ((int) ($task["submission_count"] ?? 0)),
        default => ""
    };
}

function report_spreadsheet_value(string $text): string
{
    if ($text !== "" && preg_match('/^[=+\\-@]/', $text) === 1) {
        return "'" . $text;
    }

    return $text;
}

function report_pdf_ascii(string $text): string
{
    $converted = iconv("UTF-8", "ASCII//TRANSLIT//IGNORE", $text);
    $text = $converted === false ? $text : $converted;
    $text = preg_replace('/[^\x20-\x7E]/', "?", $text) ?? "";
    return str_replace(
        ["\\", "(", ")"],
        ["\\\\", "\\(", "\\)"],
        $text
    );
}

function report_pdf_lines(string $text, int $width = 88): array
{
    $text = preg_replace('/\s+/', " ", trim($text)) ?? "";
    if ($text === "") {
        return [""];
    }

    return explode("\n", wordwrap($text, $width, "\n", true));
}

function report_build_pdf(array $tasks, array $columns, array $filters): string
{
    $lines = [
        "Todo App - Gorev Raporu",
        "Olusturma: " . date("Y-m-d H:i:s"),
        "Toplam kayit: " . count($tasks),
        "Filtreler: "
            . ($filters["status"] !== "" ? report_status_label($filters["status"]) : "Tum durumlar")
            . " / "
            . ($filters["priority"] !== "" ? report_priority_label($filters["priority"]) : "Tum oncelikler")
            . " / "
            . ($filters["user_id"] > 0 ? "Kullanici #" . $filters["user_id"] : "Tum kullanicilar"),
        str_repeat("-", 95)
    ];

    foreach ($tasks as $task) {
        $summary = "#" . report_cell($task, "ID")
            . " | " . report_cell($task, "Görev")
            . " | " . report_cell($task, "Kullanıcı")
            . " | " . report_cell($task, "Öncelik")
            . " | " . report_cell($task, "Durum")
            . " | Son tarih: " . report_cell($task, "Son Tarih");
        foreach (report_pdf_lines($summary) as $line) {
            $lines[] = $line;
        }

        $details = "Aciklama: " . report_cell($task, "Açıklama")
            . " | Gonderim: " . report_cell($task, "Gönderim Sayısı");
        foreach (report_pdf_lines($details, 88) as $line) {
            $lines[] = $line;
        }
        $lines[] = "";
    }

    if (count($lines) === 5) {
        $lines[] = "Filtrelere uyan gorev bulunamadi.";
    }

    $pages = array_chunk($lines, 48);
    $objects = [];
    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $page_refs = [];
    $next_object = 3;

    foreach ($pages as $page_lines) {
        $page_object = $next_object++;
        $content_object = $next_object++;
        $page_refs[] = $page_object . " 0 R";

        $content = "BT\n/F1 9 Tf\n40 800 Td\n";
        foreach ($page_lines as $index => $line) {
            if ($index > 0) {
                $content .= "0 -14 Td\n";
            }
            $content .= "(" . report_pdf_ascii($line) . ") Tj\n";
        }
        $content .= "ET";

        $objects[$page_object] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 0 0 R >> >> /Contents " . $content_object . " 0 R >>";
        $objects[$content_object] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
    }

    $objects[2] = "<< /Type /Pages /Kids [" . implode(" ", $page_refs) . "] /Count " . count($page_refs) . " >>";
    $font_object = $next_object++;
    $objects[0] = "";
    $objects[$font_object] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

    foreach ($objects as $object_id => $object_value) {
        if ($object_id === 0 || $object_id === $font_object) {
            continue;
        }
        $objects[$object_id] = str_replace("/F1 0 0 R", "/F1 " . $font_object . " 0 R", $object_value);
    }

    ksort($objects);
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];

    foreach ($objects as $object_id => $object_value) {
        if ($object_id === 0) {
            continue;
        }
        $offsets[$object_id] = strlen($pdf);
        $pdf .= $object_id . " 0 obj\n" . $object_value . "\nendobj\n";
    }

    $xref_offset = strlen($pdf);
    $max_object = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($max_object + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($index = 1; $index <= $max_object; $index++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$index] ?? 0);
    }

    $pdf .= "trailer\n<< /Size " . ($max_object + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xref_offset . "\n%%EOF";

    return $pdf;
}

header("X-Content-Type-Options: nosniff");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");

if ($format === "csv") {
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=todoapp_gorev_raporu_" . $stamp . ".csv");
    echo "\xEF\xBB\xBF";
    $output = fopen("php://output", "wb");
    fputcsv($output, $columns, ";");
    foreach ($tasks as $task) {
        $row = [];
        foreach ($columns as $column) {
            $row[] = report_spreadsheet_value(report_cell($task, $column));
        }
        fputcsv($output, $row, ";");
    }
    fclose($output);
    exit;
}

if ($format === "xls") {
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=todoapp_gorev_raporu_" . $stamp . ".xls");
    echo "<html><head><meta charset=\"UTF-8\"></head><body>";
    echo "<table border=\"1\"><thead><tr>";
    foreach ($columns as $column) {
        echo "<th>" . report_h($column) . "</th>";
    }
    echo "</tr></thead><tbody>";
    foreach ($tasks as $task) {
        echo "<tr>";
        foreach ($columns as $column) {
            echo "<td>" . nl2br(report_h(report_spreadsheet_value(report_cell($task, $column)))) . "</td>";
        }
        echo "</tr>";
    }
    echo "</tbody></table></body></html>";
    exit;
}

header("Content-Type: application/pdf");
header("Content-Disposition: attachment; filename=todoapp_gorev_raporu_" . $stamp . ".pdf");
echo report_build_pdf($tasks, $columns, $filters);
exit;
