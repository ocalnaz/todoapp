<?php

declare(strict_types=1);

// Bu dosya yalnızca Windows Görev Zamanlayıcı tarafından CLI üzerinden çalıştırılmalıdır.
if (PHP_SAPI !== "cli") {
    http_response_code(403);
    exit("Bu script yalnızca komut satırından çalıştırılabilir.\n");
}

require_once __DIR__ . "/../config/database.php";

$timezone = new DateTimeZone("Europe/Istanbul");
$today = new DateTimeImmutable("today", $timezone);
$log_directory = __DIR__ . "/logs";
$log_file = $log_directory . "/deadline_reminder.log";

if (!is_dir($log_directory)) {
    @mkdir($log_directory, 0775, true);
}

function write_job_log(string $message, string $log_file): void
{
    $timestamp = (new DateTimeImmutable("now", new DateTimeZone("Europe/Istanbul")))
        ->format("Y-m-d H:i:s");

    @file_put_contents(
        $log_file,
        "[" . $timestamp . "] " . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

try {
    $db->beginTransaction();

    $task_stmt = $db->prepare(
        "SELECT
            tasks.id,
            tasks.assigned_to,
            tasks.title,
            tasks.due_date,
            tasks.status
         FROM tasks
         INNER JOIN users
             ON users.id = tasks.assigned_to
                  WHERE tasks.due_date IS NOT NULL
           AND TRIM(tasks.due_date) <> ''
           AND tasks.deleted_at IS NULL

         ORDER BY tasks.due_date ASC"
    );
    $task_stmt->execute();
    $tasks = $task_stmt->fetchAll(PDO::FETCH_ASSOC);

    $notification_check = $db->prepare(
        "SELECT COUNT(*)
         FROM notifications
         WHERE user_id = ?
           AND title = ?
           AND message = ?"
    );

    $notification_insert = $db->prepare(
        "INSERT INTO notifications
            (user_id, title, message, is_read, created_at)
         VALUES (?, ?, ?, 0, CURRENT_TIMESTAMP)"
    );

    $created_count = 0;
    $skipped_count = 0;

    $completed_statuses = [
        "tamamlandı",
        "tamamlandi",
        "completed",
        "onaylandı",
        "onaylandi"
    ];

    foreach ($tasks as $task) {
        $status = strtolower(trim((string) ($task["status"] ?? "")));

        if (in_array($status, $completed_statuses, true)) {
            continue;
        }

        $due_date_value = trim((string) ($task["due_date"] ?? ""));
        $task_title = trim((string) ($task["title"] ?? ""));
        $user_id = (int) ($task["assigned_to"] ?? 0);

        if ($due_date_value === "" || $task_title === "" || $user_id <= 0) {
            continue;
        }

        try {
            $deadline_date = new DateTimeImmutable(
                substr($due_date_value, 0, 10),
                $timezone
            );
        } catch (Exception $e) {
            write_job_log(
                "Geçersiz son tarih atlandı. task_id=" . (int) ($task["id"] ?? 0),
                $log_file
            );
            continue;
        }

        $days_left = (int) $today->diff($deadline_date)->format("%r%a");

        if ($days_left < 0) {
            $reminder_title = "🔴 Görev Süresi Doldu";
            $reminder_message = "“" . $task_title . "” görevinin son tarihi geçmiştir.";
        } elseif ($days_left === 0) {
            $reminder_title = "🚨 Görev Son Günü";
            $reminder_message = "“" . $task_title . "” görevinin son günü bugün.";
        } elseif ($days_left === 1) {
            $reminder_title = "⚠️ Deadline Yaklaşıyor";
            $reminder_message = "“" . $task_title . "” görevinin bitmesine 1 gün kaldı.";
        } elseif ($days_left <= 3) {
            $reminder_title = "⏰ Deadline Yaklaşıyor";
            $reminder_message = "“" . $task_title . "” görevinin bitmesine "
                . $days_left . " gün kaldı.";
        } else {
            continue;
        }

        // Dashboard'daki mevcut kontrol ile aynı anahtar kullanılır.
        // Böylece dashboard ve otomatik görev aynı bildirimi çoğaltmaz.
        $notification_check->execute([
            $user_id,
            $reminder_title,
            $reminder_message
        ]);

        if ((int) $notification_check->fetchColumn() > 0) {
            $skipped_count++;
            continue;
        }

        $notification_insert->execute([
            $user_id,
            $reminder_title,
            $reminder_message
        ]);
        $created_count++;
    }

    $db->commit();

    $summary = "Tamamlandı. Oluşturulan=" . $created_count
        . ", mevcut olduğu için atlanan=" . $skipped_count;

    write_job_log($summary, $log_file);
    echo $summary . PHP_EOL;

} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    $error_message = "Hata: " . $e->getMessage();
    write_job_log($error_message, $log_file);
    fwrite(STDERR, $error_message . PHP_EOL);
    exit(1);
}

?>
