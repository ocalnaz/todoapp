<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $is_https = !empty($_SERVER["HTTPS"])
        && strtolower((string) $_SERVER["HTTPS"]) !== "off";

    session_set_cookie_params([
        "lifetime" => 0,
        "path" => "/",
        "secure" => $is_https,
        "httponly" => true,
        "samesite" => "Lax"
    ]);

    session_start();
}
