<?php

declare(strict_types=1);

require_once __DIR__ . "/config/session.php";
require_once __DIR__ . "/config/database.php";

if (!isset($_SESSION["user_id"], $_SESSION["role"])) {
    http_response_code(403);
    exit("Bu dosyaya erişim yetkiniz yok.");
}

$viewer_id = filter_var(
    $_SESSION["user_id"],
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);
$viewer_role = (string) $_SESSION["role"];

if ($viewer_id === false || !in_array($viewer_role, ["admin", "user"], true)) {
    http_response_code(403);
    exit("Bu dosyaya erişim yetkiniz yok.");
}

function resolveTaskFile(string $stored_path): string|false
{
    $normalized = str_replace("\\", "/", trim($stored_path));

    if (
        $normalized === ""
        || strpos($normalized, "\0") !== false
        || strpos($normalized, "://") !== false
        || strpos($normalized, "//") === 0
    ) {
        return false;
    }

    $normalized = ltrim($normalized, "/");
    while (strpos($normalized, "../") === 0) {
        $normalized = substr($normalized, 3);
    }

    if ($normalized === "" || strpos($normalized, "..") !== false) {
        return false;
    }

    if (strpos($normalized, "uploads/task_submissions/") !== 0) {
        $file_name = basename($normalized);

        if (
            $file_name === ""
            || $file_name === "."
            || $file_name === ".."
            || !preg_match('/^[A-Za-z0-9._-]+$/D', $file_name)
        ) {
            return false;
        }

        $normalized = "uploads/task_submissions/" . $file_name;
    }

    $root = realpath(__DIR__ . "/uploads/task_submissions");
    $candidate = realpath(
        __DIR__ . "/" . str_replace("/", DIRECTORY_SEPARATOR, $normalized)
    );

    if (
        $root === false
        || $candidate === false
        || !is_file($candidate)
        || strpos($candidate, $root . DIRECTORY_SEPARATOR) !== 0
    ) {
        return false;
    }

    return $candidate;
}

$attachment_id = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);
$submission_id = filter_input(
    INPUT_GET,
    "submission_id",
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

$file_record = false;
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
    $attachment_name_column = "original_name";
}

if ($attachment_id !== false && $attachment_id !== null) {
    $stmt = $db->prepare(
        "SELECT
            task_submission_files.file_path,
            task_submission_files." . $attachment_name_column . " AS original_name,
            task_submissions.user_id,
            tasks.assigned_by
         FROM task_submission_files
         INNER JOIN task_submissions
             ON task_submission_files.submission_id = task_submissions.id
         INNER JOIN tasks
             ON task_submissions.task_id = tasks.id
         WHERE task_submission_files.id = ?
           AND tasks.deleted_at IS NULL
           AND (
                (? = 'admin' AND tasks.assigned_by = ?)
                OR (? = 'user' AND task_submissions.user_id = ?)
           )
         LIMIT 1"
    );
    $stmt->execute([
        $attachment_id,
        $viewer_role,
        $viewer_id,
        $viewer_role,
        $viewer_id
    ]);
    $file_record = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif ($submission_id !== false && $submission_id !== null) {
    $stmt = $db->prepare(
        "SELECT
            task_submissions.file_path,
            task_submissions.file_name AS original_name,
            task_submissions.user_id,
            tasks.assigned_by
         FROM task_submissions
         INNER JOIN tasks
             ON task_submissions.task_id = tasks.id
         WHERE task_submissions.id = ?
           AND tasks.deleted_at IS NULL
           AND (
                (? = 'admin' AND tasks.assigned_by = ?)
                OR (? = 'user' AND task_submissions.user_id = ?)
           )
         LIMIT 1"
    );
    $stmt->execute([
        $submission_id,
        $viewer_role,
        $viewer_id,
        $viewer_role,
        $viewer_id
    ]);
    $file_record = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$file_record) {
    http_response_code(404);
    exit("Dosya bulunamadı.");
}

$absolute_path = resolveTaskFile(
    (string) ($file_record["file_path"] ?? "")
);

if ($absolute_path === false) {
    http_response_code(404);
    exit("Dosya bulunamadı.");
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = $finfo !== false
    ? finfo_file($finfo, $absolute_path)
    : false;

if ($finfo !== false) {
    finfo_close($finfo);
}

$mime_type = is_string($mime_type) && $mime_type !== ""
    ? $mime_type
    : "application/octet-stream";

$download_name = (string) ($file_record["original_name"] ?? "");
$download_name = preg_replace(
    '/[^A-Za-z0-9._-]+/u',
    "_",
    basename($download_name)
) ?: basename($absolute_path);

header("Content-Type: " . $mime_type);
header("Content-Length: " . (string) filesize($absolute_path));
header("Content-Disposition: inline; filename=\"" . $download_name . "\"");
header("X-Content-Type-Options: nosniff");
header("Cache-Control: private, no-store");

readfile($absolute_path);
exit;
