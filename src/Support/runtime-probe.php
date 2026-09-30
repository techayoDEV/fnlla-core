<?php

declare(strict_types=1);

// Internal isolated probe: never bootstrap application providers or print exceptions.
$result = ["status" => "error", "code" => "probe_failed"];
try {
    $input = json_decode((string) stream_get_contents(STDIN, 4097), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input)) { throw new RuntimeException(); }
    $config = $input["config"] ?? [];
    $timeout = max(1, min(10, (int) ($input["timeout"] ?? 2)));
    switch ($input["type"] ?? "") {
        case "mysql":
            if (!extension_loaded("pdo_mysql")) { $result = ["status" => "unavailable", "code" => "pdo_mysql_missing"]; break; }
            $host = (string) ($config["host"] ?? "127.0.0.1");
            $database = (string) ($config["database"] ?? "");
            $port = (int) ($config["port"] ?? 3306);
            if (strpbrk($host . $database, ";\r\n\0") !== false || $database === "" || $port < 1 || $port > 65535) {
                throw new RuntimeException();
            }
            $options = [PDO::ATTR_TIMEOUT => $timeout, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];
            $ssl = $config["ssl"] ?? [];
            if (($ssl["enabled"] ?? false) === true) {
                foreach (["ca" => PDO::MYSQL_ATTR_SSL_CA, "cert" => PDO::MYSQL_ATTR_SSL_CERT, "key" => PDO::MYSQL_ATTR_SSL_KEY] as $key => $option) {
                    if (($ssl[$key] ?? "") !== "") { $options[$option] = $ssl[$key]; }
                }
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (bool) ($ssl["verify_server_cert"] ?? true);
            }
            $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                (string) ($config["username"] ?? ""), (string) ($config["password"] ?? ""),
                $options);
            $result = (int) $pdo->query("SELECT 1")->fetchColumn() === 1
                ? ["status" => "ready", "code" => "query_ok"] : $result;
            break;
        case "redis":
            if (!class_exists(Redis::class)) { $result = ["status" => "unavailable", "code" => "redis_extension_missing"]; break; }
            $redis = new Redis();
            $redis->connect((string) ($config["host"] ?? "127.0.0.1"), (int) ($config["port"] ?? 6379), $timeout);
            $redis->setOption(Redis::OPT_READ_TIMEOUT, (float) $timeout);
            if (($config["password"] ?? "") !== "") { $redis->auth((string) $config["password"]); }
            $redis->select((int) ($config["database"] ?? 0));
            if ($redis->ping() === false) { throw new RuntimeException(); }
            $redis->close();
            $result = ["status" => "ready", "code" => "ping_ok"];
            break;
        case "directory":
            $path = (string) ($config["path"] ?? "");
            $result = is_dir($path) && is_readable($path) && is_writable($path)
                ? ["status" => "ready", "code" => "directory_accessible"]
                : ["status" => "error", "code" => "directory_unavailable"];
            break;
    }
} catch (Throwable) {
    // Neither connection details nor driver errors cross the diagnostic boundary.
}
echo json_encode($result, JSON_THROW_ON_ERROR);
