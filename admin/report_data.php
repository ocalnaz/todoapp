<?php

declare(strict_types=1);

function report_allowed_statuses(): array
{
    return [
        "bekliyor",
        "devam ediyor",
        "tamamlandı",
        "incelemede",
        "revizyon",
        "onaylandı"
    ];
}

function report_allowed_priorities(): array
{
    return ["urgent", "high", "normal", "low"];
}

function report_filters(array $source): array
{
    $status = (string) ($source["status"] ?? "");
    $priority = (string) ($source["priority"] ?? "");
    $user_id = filter_var(
        $source["user_id"] ?? null,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1]]
    );

    return [
        "status" => in_array($status, report_allowed_statuses(), true) ? $status : "",
        "priority" => in_array($priority, report_allowed_priorities(), true) ? $priority : "",
        "user_id" => $user_id === false ? 0 : (int) $user_id,
        "include_archived" => filter_var(
            $source["include_archived"] ?? false,
            FILTER_VALIDATE_BOOLEAN
        )
    ];
}

function report_fetch_tasks(PDO $db, int $admin_id, array $filters): array
{
    $where = ["t.assigned_by = :admin_id"];
    $params = [":admin_id" => $admin_id];

    if (!$filters["include_archived"]) {
        $where[] = "t.deleted_at IS NULL";
    }

    if ($filters["status"] !== "") {
        $where[] = "t.status = :status";
        $params[":status"] = $filters["status"];
    }

    if ($filters["priority"] !== "") {
        $where[] = "t.priority = :priority";
        $params[":priority"] = $filters["priority"];
    }

    if ((int) $filters["user_id"] > 0) {
        $where[] = "t.assigned_to = :assigned_to";
        $params[":assigned_to"] = (int) $filters["user_id"];
    }

    $sql = "SELECT
                t.id,
                t.title,
                t.description,
                t.due_date,
                t.status,
                t.priority,
                t.created_at,
                t.deleted_at,
                u.full_name AS user_name,
                u.username,
                assigned_by_user.full_name AS assigned_by_name,
                COUNT(DISTINCT ts.id) AS submission_count
            FROM tasks t
            LEFT JOIN users u ON t.assigned_to = u.id
            LEFT JOIN users assigned_by_user ON t.assigned_by = assigned_by_user.id
            LEFT JOIN task_submissions ts ON ts.task_id = t.id
            WHERE " . implode(" AND ", $where) . "
            GROUP BY t.id
            ORDER BY t.created_at DESC, t.id DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function report_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, "UTF-8");
}

function report_status_label(string $status): string
{
    $labels = [
        "bekliyor" => "Bekliyor",
        "devam ediyor" => "Devam ediyor",
        "tamamlandı" => "Tamamlandı",
        "incelemede" => "İncelemede",
        "revizyon" => "Revizyon",
        "onaylandı" => "Onaylandı"
    ];

    return $labels[$status] ?? $status;
}

function report_priority_label(string $priority): string
{
    $labels = [
        "urgent" => "Acil",
        "high" => "Yüksek",
        "normal" => "Normal",
        "low" => "Düşük"
    ];

    return $labels[$priority] ?? "Normal";
}

function report_query(array $filters): string
{
    $query = [];

    if ($filters["status"] !== "") {
        $query["status"] = $filters["status"];
    }
    if ($filters["priority"] !== "") {
        $query["priority"] = $filters["priority"];
    }
    if ((int) $filters["user_id"] > 0) {
        $query["user_id"] = (int) $filters["user_id"];
    }
    if ($filters["include_archived"]) {
        $query["include_archived"] = "1";
    }

    return http_build_query($query);
}
