<?php

function appBasePath(): string
{
    $scriptName = str_replace("\\", "/", (string) ($_SERVER["SCRIPT_NAME"] ?? ""));

    if (preg_match("#^(.*)/(?:admin|teacher|student|registrar)/[^/]+\.php$#i", $scriptName, $matches)) {
        return rtrim($matches[1], "/");
    }

    $requestPath = parse_url((string) ($_SERVER["REQUEST_URI"] ?? ""), PHP_URL_PATH);
    if (is_string($requestPath) && preg_match("#^(.*)/(?:admin|teacher|student|registrar)-[^/]+$#i", $requestPath, $matches)) {
        return rtrim($matches[1], "/");
    }

    $directory = str_replace("\\", "/", dirname($scriptName));
    return $directory === "/" || $directory === "." ? "" : rtrim($directory, "/");
}

function appUrl(string $path = ""): string
{
    return appBasePath() . "/" . ltrim($path, "/");
}

function rolePageUrl(string $role, string $page): string
{
    return appUrl(strtolower($role) . "-" . preg_replace("/\.php$/i", "", basename($page)));
}
