<?php

declare(strict_types=1);

/*
===============================================================================
FNLLA SUPPORT SOURCE
File: src\Support\helpers.php
Copyright (c) 2026 TechAyo LTD (techayo.co.uk). Released under the MIT License.
===============================================================================

FNLLA is produced, maintained and distributed by TechAyo LTD
(techayo.co.uk). This repository is the authoritative maintainer workspace for
the FNLLA framework released under the MIT License and its related delivery scripts, tests,
templates and release metadata.

Purpose:
- Implements shared helpers, environment loading, metadata and framework support behaviour.
*/

use Fnlla\Php\Container\Container;
use Fnlla\Php\Session\RedisSessionHandler;

function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false) {
        return $default;
    }

    if (!is_string($value)) {
        return $value;
    }

    return match (strtolower(trim($value))) {
        "true", "(true)" => true,
        "false", "(false)" => false,
        "null", "(null)" => null,
        "empty", "(empty)" => "",
        default => $value,
    };
}

function framework_detect_environment(): string
{
    $explicit = $_ENV["APP_ENV"] ?? $_SERVER["APP_ENV"] ?? getenv("APP_ENV");

    if (is_string($explicit) && trim($explicit) !== "") {
        return trim($explicit);
    }

    return is_file(env_file_path()) ? "production" : "development";
}

function framework_trusted_proxies(): array
{
    $raw = trim((string) env("TRUSTED_PROXIES", ""));

    if ($raw === "") {
        return [];
    }

    $entries = preg_split('/[\s,;]+/', $raw) ?: [];

    return array_values(array_filter(
        array_map(static fn (string $entry): string => trim($entry), $entries),
        static fn (string $entry): bool => $entry !== ""
    ));
}

function framework_remote_addr(array $server): string
{
    return (string) ($server["REMOTE_ADDR"] ?? "0.0.0.0");
}

function framework_request_comes_from_trusted_proxy(array $server): bool
{
    $remoteAddr = framework_remote_addr($server);

    if (filter_var($remoteAddr, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    foreach (framework_trusted_proxies() as $trustedProxy) {
        $normalized = strtolower($trustedProxy);

        if (($normalized === "loopback" || $normalized === "localhost") && in_array($remoteAddr, ["127.0.0.1", "::1"], true)) {
            return true;
        }

        if (str_contains($trustedProxy, "/")) {
            [$network, $prefixLength] = array_pad(explode("/", $trustedProxy, 2), 2, "");
            $networkPacked = inet_pton(trim($network));
            $addressPacked = inet_pton($remoteAddr);
            $prefix = preg_match('/^[0-9]+$/D', $prefixLength) === 1 ? (int) $prefixLength : -1;

            if ($networkPacked === false || $addressPacked === false || strlen($networkPacked) !== strlen($addressPacked)) {
                continue;
            }

            $maxBits = strlen($networkPacked) * 8;

            if ($prefix < 0 || $prefix > $maxBits) {
                continue;
            }

            $fullBytes = intdiv($prefix, 8);
            $remainingBits = $prefix % 8;

            if ($fullBytes > 0 && substr($addressPacked, 0, $fullBytes) !== substr($networkPacked, 0, $fullBytes)) {
                continue;
            }

            if ($remainingBits === 0) {
                return true;
            }

            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            $addressByte = ord($addressPacked[$fullBytes]);
            $networkByte = ord($networkPacked[$fullBytes]);

            if (($addressByte & $mask) === ($networkByte & $mask)) {
                return true;
            }

            continue;
        }

        if (strcasecmp($remoteAddr, $trustedProxy) === 0) {
            return true;
        }
    }

    return false;
}

function framework_trusted_forwarded_ip(array $server, array $headers = []): ?string
{
    if (!framework_request_comes_from_trusted_proxy($server)) {
        return null;
    }

    $forwardedFor = $headers["x-forwarded-for"] ?? $server["HTTP_X_FORWARDED_FOR"] ?? "";

    if (!is_string($forwardedFor) || trim($forwardedFor) === "" || strlen($forwardedFor) > 8192) {
        return null;
    }

    $chain = explode(",", $forwardedFor);
    if (count($chain) > 64) { return null; }
    $lastTrusted = null;
    // Only the suffix supplied by trusted proxies is authoritative, never a client-supplied prefix.
    foreach (array_reverse($chain) as $candidate) {
        $candidate = trim($candidate);
        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) { return null; }
        if (!framework_request_comes_from_trusted_proxy(["REMOTE_ADDR" => $candidate])) {
            return $candidate;
        }
        $lastTrusted = $candidate;
    }

    return $lastTrusted;
}

function framework_request_ip(array $server, array $headers = []): string
{
    return framework_trusted_forwarded_ip($server, $headers) ?? framework_remote_addr($server);
}

function app_request_is_secure(): bool
{
    if (framework_request_comes_from_trusted_proxy($_SERVER)) {
        $forwardedProto = $_SERVER["HTTP_X_FORWARDED_PROTO"] ?? null;

        if ($forwardedProto !== null) {
            // Trusted ingress must overwrite this header with one canonical scheme.
            // Ambiguous chains cannot establish transport security.
            return is_string($forwardedProto) && strtolower(trim($forwardedProto)) === "https";
        }

        if (strtolower((string) ($_SERVER["HTTP_X_FORWARDED_SSL"] ?? "")) === "on") {
            return true;
        }
    }

    $https = $_SERVER["HTTPS"] ?? null;

    if (is_string($https) && $https !== "" && strtolower($https) !== "off") {
        return true;
    }

    $requestScheme = strtolower((string) ($_SERVER["REQUEST_SCHEME"] ?? ""));

    if ($requestScheme === "https") {
        return true;
    }

    if ((int) ($_SERVER["SERVER_PORT"] ?? 0) === 443) {
        return true;
    }

    $appUrl = strtolower(trim((string) env("APP_URL", "")));

    return $appUrl !== "" && str_starts_with($appUrl, "https://");
}

function config(?string $key = null, mixed $default = null): mixed
{
    $config = $GLOBALS["fnlla_config"] ?? $GLOBALS["fnlla_php_config"] ?? [];

    if ($key === null || $key === "") {
        return $config;
    }

    $segments = explode(".", $key);
    $value = $config;

    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}

function config_set(string $key, mixed $value): void
{
    $config = $GLOBALS["fnlla_config"] ?? $GLOBALS["fnlla_php_config"] ?? [];
    $segments = explode(".", $key);
    $cursor = &$config;

    foreach ($segments as $segment) {
        if (!is_array($cursor)) {
            $cursor = [];
        }

        if (!array_key_exists($segment, $cursor) || !is_array($cursor[$segment])) {
            $cursor[$segment] ??= [];
        }

        $cursor = &$cursor[$segment];
    }

    $cursor = $value;
    $GLOBALS["fnlla_config"] = $config;
    $GLOBALS["fnlla_php_config"] = $config;
}

function load_config_directory(string $directory): array
{
    $cachedConfig = framework_config_cache_path();

    if (is_file($cachedConfig)) {
        $config = require $cachedConfig;

        if (is_array($config)) {
            // Credentials must not outlive a password rotation in the bootstrap cache.
            $accessConfigPath = rtrim($directory, "\\/") . DIRECTORY_SEPARATOR . "developer_access.php";
            if (isset($config["developer_access"]) && is_file($accessConfigPath)) {
                $accessConfig = require $accessConfigPath;
                $config["developer_access"]["users"] = is_array($accessConfig) ? (string) ($accessConfig["users"] ?? "") : "";
            }
            return $config;
        }
    }

    $config = [];
    $files = glob(rtrim($directory, "\\/") . DIRECTORY_SEPARATOR . "*.php");

    if ($files === false) {
        return $config;
    }

    sort($files);

    foreach ($files as $file) {
        $key = pathinfo($file, PATHINFO_FILENAME);
        $loaded = require $file;

        if (!is_array($loaded)) {
            throw new RuntimeException("Config file must return an array: " . $file);
        }

        $config[$key] = $loaded;
    }

    return $config;
}

function framework_cache_path(string $path = ""): string
{
    if (defined("FNLLA_CACHE_ROOT")) {
        return FNLLA_CACHE_ROOT . ($path !== "" ? DIRECTORY_SEPARATOR . ltrim($path, "\\/") : "");
    }
    return storage_path("framework/cache" . ($path !== "" ? DIRECTORY_SEPARATOR . ltrim($path, "\\/") : ""));
}

function framework_config_cache_path(): string
{
    return framework_cache_path("bootstrap-config.php");
}

function framework_route_cache_path(): string
{
    return framework_cache_path("routes.php");
}

function framework_asset_manifest_path(): string
{
    return framework_cache_path("assets.php");
}

function framework_preload_path(): string
{
    return framework_cache_path("preload.php");
}

function framework_performance_baseline_path(): string
{
    return framework_cache_path("performance-baseline.json");
}







function base_path(string $path = ""): string
{
    return APP_ROOT . ($path !== "" ? DIRECTORY_SEPARATOR . ltrim($path, "\\/") : "");
}

function public_path(string $path = ""): string
{
    $relative = str_replace("\\", "/", ltrim($path, "\\/"));
    if (defined("FNLLA_SHARED_PUBLIC_ROOT") && ($relative === "uploads" || str_starts_with($relative, "uploads/"))) {
        return FNLLA_SHARED_PUBLIC_ROOT . "/" . $relative;
    }
    return PUBLIC_ROOT . ($path !== "" ? DIRECTORY_SEPARATOR . ltrim($path, "\\/") : "");
}

function storage_path(string $path = ""): string
{
    $root = defined("FNLLA_STORAGE_ROOT") ? FNLLA_STORAGE_ROOT : APP_ROOT . DIRECTORY_SEPARATOR . "storage";
    return $root . ($path !== "" ? DIRECTORY_SEPARATOR . ltrim($path, "\\/") : "");
}

function env_file_path(): string
{
    return defined("FNLLA_ENV_PATH") ? FNLLA_ENV_PATH : base_path(".env");
}

function url(string $path = ""): string
{
    $baseUrl = (string) config("app.base_url", "");
    $normalizedPath = "/" . ltrim($path, "/");

    if ($normalizedPath === "/") {
        return $baseUrl !== "" ? $baseUrl . "/" : "/";
    }

    return ($baseUrl !== "" ? $baseUrl : "") . $normalizedPath;
}

function asset(string $path = ""): string
{
    $normalizedPath = ltrim(str_replace("\\", "/", $path), "/");
    $assetBaseUrl = (string) config("app.asset_url", "");
    if ($assetBaseUrl === "" && defined("FNLLA_RELEASE_ASSET_URL") && !str_starts_with($normalizedPath, "uploads/")) {
        $assetBaseUrl = FNLLA_RELEASE_ASSET_URL;
    }
    $assetUrl = ($assetBaseUrl !== "" ? $assetBaseUrl : "") . "/" . $normalizedPath;
    $manifestPath = framework_asset_manifest_path();

    if (is_file($manifestPath)) {
        $manifest = require $manifestPath;

        if (is_array($manifest) && isset($manifest[$normalizedPath]) && is_array($manifest[$normalizedPath])) {
            $version = (string) ($manifest[$normalizedPath]["version"] ?? "");

            return $version !== "" ? $assetUrl . "?v=" . rawurlencode($version) : $assetUrl;
        }
    }

    $publicPath = public_path($normalizedPath);

    if (!is_file($publicPath)) {
        return $assetUrl;
    }

    return $assetUrl . '?v=' . (string) filemtime($publicPath);
}

function framework_brand_asset(string $key): ?string
{
    $configured = trim((string) config("framework.brand.assets." . $key, ""));

    if ($configured === "") {
        return null;
    }

    if (filter_var($configured, FILTER_VALIDATE_URL) !== false) {
        return $configured;
    }

    $normalizedPath = ltrim(str_replace("\\", "/", $configured), "/");

    if ($normalizedPath === "" || !is_file(public_path($normalizedPath))) {
        return null;
    }

    return asset($normalizedPath);
}

function framework_brand_color(string $key, string $default = ""): string
{
    $configured = trim((string) config("framework.brand.colors." . $key, ""));

    return preg_match('/^#[0-9A-Fa-f]{6}$/', $configured) === 1 ? strtoupper($configured) : $default;
}

/** Older project-owned layouts still call this helper after a framework update. */
function has_local_docs_workspace(): bool
{
    return false;
}

function page_meta(array $overrides = []): array
{
    return \Fnlla\Php\Support\PageMeta::resolve(array_merge([
        "tagline" => (string) config("app.tagline", ""),
    ], $overrides), (string) config("app.name", "FNLLA"));
}

function project_brand_mark(?string $name = null): string
{
    $name = trim((string) ($name ?? config("app.name", "FNLLA")));
    $tokens = array_values(array_filter(
        preg_split('/[^A-Za-z0-9]+/', $name) ?: [],
        static fn (string $token): bool => $token !== ""
    ));

    if (count($tokens) >= 2) {
        return strtoupper(substr($tokens[0], 0, 1) . substr($tokens[1], 0, 1));
    }

    $compact = preg_replace('/[^A-Za-z0-9]/', "", $name) ?? "";

    return strtoupper(substr($compact !== "" ? $compact : "FN", 0, 2));
}

function project_brand_logo_asset(?string $path = null): ?string
{
    $configured = trim((string) ($path ?? config("app.brand_logo", "auto")));

    if ($configured === "" || strtolower($configured) === "none") {
        return null;
    }

    if (strtolower($configured) === "auto") {
        $appName = strtolower(trim((string) config("app.name", "FNLLA")));
        $configured = $appName === "fnlla" ? "assets/brand/fnlla/favicon.svg" : "";
    }

    if ($configured === "") {
        return null;
    }

    if (filter_var($configured, FILTER_VALIDATE_URL) !== false) {
        return $configured;
    }

    $normalizedPath = ltrim(str_replace("\\", "/", $configured), "/");

    if ($normalizedPath === "" || !is_file(public_path($normalizedPath))) {
        return null;
    }

    return asset($normalizedPath);
}

function panel_branding(): array
{
    return \Fnlla\Php\Support\PanelBranding::state();
}


function route(string $name, array $parameters = []): string
{
    return app(\Fnlla\Php\Routing\UrlGenerator::class)->route($name, $parameters);
}

function h(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function app_environment(): string
{
    return (string) config("app.environment", "production");
}

function app_debug(): bool
{
    return (bool) config("app.debug", false);
}

function request_id(): string
{
    $current = $_SERVER["FNLLA_REQUEST_ID"] ?? $_SERVER["HTTP_X_REQUEST_ID"] ?? null;
    $normalized = is_string($current) ? framework_normalize_request_id($current) : null;

    if ($normalized !== null) {
        $_SERVER["FNLLA_REQUEST_ID"] = $normalized;

        return $normalized;
    }

    $generated = bin2hex(random_bytes(16));
    $_SERVER["FNLLA_REQUEST_ID"] = $generated;

    return $generated;
}

function framework_normalize_request_id(string $requestId): ?string
{
    $requestId = trim($requestId);

    if ($requestId === "" || strlen($requestId) > 128) {
        return null;
    }

    if (preg_match('/^[A-Za-z0-9._:-]+$/', $requestId) !== 1) {
        return null;
    }

    return $requestId;
}

function framework_start_session_if_needed(): void
{
    if (PHP_SAPI === "cli" && session_status() !== PHP_SESSION_ACTIVE) {
        $_SESSION = is_array($_SESSION ?? null) ? $_SESSION : [];
        framework_bootstrap_session_state();
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        framework_bootstrap_session_state();
        return;
    }

    if (headers_sent()) {
        throw new RuntimeException("Cannot start a persistent session after response output.");
    }

    $sessionConfig = config("session", []);
    $sessionPath = (string) config("app.session_path");
    $driver = (string) ($sessionConfig["driver"] ?? "file");
    if (!in_array($driver, ["file", "redis"], true)) {
        throw new RuntimeException("Unsupported session driver.");
    }
    if ($driver === "file") {
        if (!is_dir($sessionPath) && !mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) {
            throw new RuntimeException("Cannot create session storage.");
        }
        if (ini_set("session.save_handler", "files") === false) { throw new RuntimeException("Cannot configure file session storage."); }
        session_save_path($sessionPath);
    }

    session_name((string) ($sessionConfig["name"] ?? "fnlla_session"));
    ini_set("session.use_strict_mode", !empty($sessionConfig["strict_mode"]) ? "1" : "0");
    ini_set("session.use_only_cookies", !empty($sessionConfig["use_only_cookies"]) ? "1" : "0");
    ini_set("session.cookie_httponly", !empty($sessionConfig["http_only"]) ? "1" : "0");
    ini_set("session.cookie_secure", !empty($sessionConfig["secure"]) ? "1" : "0");
    ini_set("session.gc_maxlifetime", (string) ($sessionConfig["cookie_lifetime"] ?? 7200));
    if ($driver === "redis") {
        if (!session_set_save_handler(new RedisSessionHandler(
            (array) ($sessionConfig["redis"] ?? []),
            (int) ($sessionConfig["cookie_lifetime"] ?? 7200)
        ), true)) { throw new RuntimeException("Cannot configure Redis session storage."); }
    }

    session_set_cookie_params([
        "lifetime" => (int) ($sessionConfig["cookie_lifetime"] ?? 7200),
        "path" => (string) ($sessionConfig["path"] ?? "/"),
        "domain" => is_string($sessionConfig["domain"] ?? null) ? (string) $sessionConfig["domain"] : "",
        "secure" => !empty($sessionConfig["secure"]),
        "httponly" => !empty($sessionConfig["http_only"]),
        "samesite" => (string) ($sessionConfig["same_site"] ?? "Lax"),
    ]);
    if (!session_start()) {
        throw new RuntimeException("Cannot start persistent session storage.");
    }
    framework_bootstrap_session_state();
}

function framework_bootstrap_session_state(): void
{
    static $bootstrapped = false;

    if ($bootstrapped && isset($_SESSION["_meta"]) && is_array($_SESSION["_meta"])) {
        return;
    }

    $_SESSION = is_array($_SESSION ?? null) ? $_SESSION : [];
    $sessionConfig = config("session", []);
    $now = time();
    $persistent = session_status() === PHP_SESSION_ACTIVE;
    $meta = $_SESSION["_meta"] ?? null;
    $started = is_array($meta) ? ($meta["started_at"] ?? null) : null;
    // Old sessions have no activity timestamp; their last rotation is a bounded fallback.
    $lastActivity = is_array($meta) ? ($meta["last_activity_at"] ?? $meta["last_regenerated_at"] ?? null) : null;
    $idleSeconds = max(1, (int) ($sessionConfig["lifetime_minutes"] ?? 120)) * 60;
    $absoluteSeconds = max(1, (int) ($sessionConfig["absolute_lifetime_minutes"] ?? 720)) * 60;
    if ($persistent && (!is_int($started) || !is_int($lastActivity)
        || $started <= 0 || $lastActivity < $started || $started > $now || $lastActivity > $now
        || $now - $lastActivity >= $idleSeconds || $now - $started >= $absoluteSeconds)) {
        // Expiry revokes every identity domain and CSRF state before accepting this request.
        $_SESSION = [];
    }

    if (!isset($_SESSION["_meta"]) || !is_array($_SESSION["_meta"])) {
        if ($persistent && !session_regenerate_id(true)) {
            throw new RuntimeException("Session identifier could not be initialized.");
        }

        $_SESSION["_meta"] = [
            "started_at" => $now,
            "last_regenerated_at" => $now,
        ];
    } else {
        $rotationWindow = max(1, (int) ($sessionConfig["rotate_after_minutes"] ?? 30)) * 60;
        $lastRegeneratedAt = (int) ($_SESSION["_meta"]["last_regenerated_at"] ?? 0);

        if ($lastRegeneratedAt <= 0 || $lastRegeneratedAt > $now || ($now - $lastRegeneratedAt) >= $rotationWindow) {
            if ($persistent && !session_regenerate_id(true)) {
                $_SESSION = [];
                throw new RuntimeException("Session identifier could not be rotated.");
            }

            $_SESSION["_meta"]["last_regenerated_at"] = $now;
        }
    }
    $_SESSION["_meta"]["last_activity_at"] = $now;

    /*
    Flash data normally moves from `_flash` to `_flash_old` at the beginning of
    the next request. When a test or CLI command seeds `_flash_old` directly and
    no new `_flash` bucket exists yet, preserve it instead of erasing it.
    */
    if (isset($_SESSION["_flash"]) && is_array($_SESSION["_flash"])) {
        $_SESSION["_flash_old"] = $_SESSION["_flash"];
    } elseif (!isset($_SESSION["_flash_old"]) || !is_array($_SESSION["_flash_old"])) {
        $_SESSION["_flash_old"] = [];
    }

    $_SESSION["_flash"] = [];
    $bootstrapped = true;
}

function app(?string $abstract = null, array $parameters = []): mixed
{
    $container = $GLOBALS["fnlla_container"] ?? $GLOBALS["fnlla_php_container"] ?? null;

    if (!$container instanceof Container) {
        throw new RuntimeException("Application container has not been bootstrapped.");
    }

    if ($abstract === null) {
        return $container;
    }

    return $container->make($abstract, $parameters);
}

function current_path(): string
{
    $requestUri = $_SERVER["REQUEST_URI"] ?? "/";
    $path = parse_url($requestUri, PHP_URL_PATH) ?: "/";
    $normalized = "/" . trim($path, "/");

    return $normalized === "/" ? "/" : rtrim($normalized, "/");
}

function is_current_path(string $path): bool
{
    $normalized = "/" . trim($path, "/");
    $normalized = $normalized === "/" ? "/" : rtrim($normalized, "/");

    return current_path() === $normalized;
}

function flash(string $key, mixed $default = null): mixed
{
    framework_start_session_if_needed();
    $flash = $_SESSION["_flash_old"] ?? [];

    return $flash[$key] ?? $default;
}

function flash_set(string $key, mixed $value): void
{
    framework_start_session_if_needed();
    if (!isset($_SESSION["_flash"]) || !is_array($_SESSION["_flash"])) {
        $_SESSION["_flash"] = [];
    }

    $_SESSION["_flash"][$key] = $value;
}

function old(string $key, mixed $default = ""): mixed
{
    $old = flash("old", []);

    return is_array($old) ? ($old[$key] ?? $default) : $default;
}

function errors(): array
{
    $errors = flash("errors", []);

    return is_array($errors) ? $errors : [];
}

function error_for(string $field): ?string
{
    $allErrors = errors();

    return isset($allErrors[$field]) ? (string) $allErrors[$field] : null;
}

function csrf_token(): string
{
    framework_start_session_if_needed();
    $token = $_SESSION["_csrf_token"] ?? null;
    $issuedAt = (int) ($_SESSION["_csrf_token_issued_at"] ?? 0);
    $rotationWindow = max(1, (int) config("security.csrf.rotate_after_minutes", 120)) * 60;

    if (!is_string($token) || $token === "" || ($issuedAt > 0 && (time() - $issuedAt) >= $rotationWindow)) {
        return regenerate_csrf_token();
    }

    return $token;
}

function regenerate_csrf_token(): string
{
    framework_start_session_if_needed();
    $_SESSION["_csrf_token"] = bin2hex(random_bytes(32));
    $_SESSION["_csrf_token_issued_at"] = time();

    return $_SESSION["_csrf_token"];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . h(csrf_token()) . '">';
}

function csp_nonce(): string
{
    $nonce = $_SERVER["FNLLA_CSP_NONCE"] ?? null;

    if (is_string($nonce) && $nonce !== "") {
        return $nonce;
    }

    $nonce = rtrim(strtr(base64_encode(random_bytes(16)), "+/", "-_"), "=");
    $_SERVER["FNLLA_CSP_NONCE"] = $nonce;

    return $nonce;
}

function verify_csrf_token(?string $token): bool
{
    if (!is_string($token) || $token === "") {
        return false;
    }

    $knownToken = csrf_token();

    return hash_equals($knownToken, $token);
}

function auth(): \Fnlla\Php\Auth\AuthManager
{
    return app(\Fnlla\Php\Auth\AuthManager::class);
}

function db(): \Fnlla\Php\Database\DatabaseManager
{
    return app(\Fnlla\Php\Database\DatabaseManager::class);
}

function session_store(): \Fnlla\Php\Session\SessionStore
{
    return app(\Fnlla\Php\Session\SessionStore::class);
}

function gate(): \Fnlla\Php\Auth\Authorization\Gate
{
    return app(\Fnlla\Php\Auth\Authorization\Gate::class);
}






function cache(): \Fnlla\Php\Cache\CacheStoreInterface
{
    return app(\Fnlla\Php\Cache\CacheStoreInterface::class);
}

function storage(?string $disk = null): \Fnlla\Php\Filesystem\FilesystemAdapter
{
    return app(\Fnlla\Php\Filesystem\StorageManager::class)->disk($disk);
}

function hasher(): \Fnlla\Php\Hashing\Hasher
{
    return app(\Fnlla\Php\Hashing\Hasher::class);
}

function event(object|string $event, array $payload = []): array
{
    return app(\Fnlla\Php\Events\Dispatcher::class)->dispatch($event, $payload);
}

function translator(): \Fnlla\Php\Localization\Translator
{
    return app(\Fnlla\Php\Localization\Translator::class);
}

function __(string $key, array $replace = [], ?string $locale = null): string
{
    return translator()->get($key, $replace, $locale);
}

function mailer(): \Fnlla\Php\Mail\Mailer
{
    return app(\Fnlla\Php\Mail\Mailer::class);
}



function queue(): \Fnlla\Php\Queue\QueueManager
{
    return app(\Fnlla\Php\Queue\QueueManager::class);
}

function stream_request_body_to_file(string $destination, int $maxBytes): array
{
    $maxBytes = max(1, $maxBytes);
    $directory = dirname($destination);

    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $input = fopen("php://input", "rb");
    $output = fopen($destination, "wb");
    $bytes = 0;

    if ($input === false || $output === false) {
        throw new RuntimeException("Unable to open request body stream.");
    }

    try {
        while (!feof($input)) {
            $chunk = fread($input, 8192);
            if ($chunk === false) {
                throw new RuntimeException("Unable to read request body stream.");
            }

            $bytes += strlen($chunk);
            if ($bytes > $maxBytes) {
                throw new RuntimeException("Request body stream exceeds configured limit.");
            }

            fwrite($output, $chunk);
        }
    } finally {
        fclose($input);
        fclose($output);
    }

    return [
        "path" => $destination,
        "bytes" => $bytes,
        "sha256" => hash_file("sha256", $destination),
    ];
}

if (is_file(__DIR__ . '/optional_helpers.php')) {
    require_once __DIR__ . '/optional_helpers.php';
}
