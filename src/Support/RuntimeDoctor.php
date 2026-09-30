<?php

declare(strict_types=1);

namespace Fnlla\Php\Support;

use Throwable;

final class RuntimeDoctor
{
    /** @return array<string, mixed> */
    public function report(float $timeout = 2.0): array
    {
        $timeout = max(0.1, min(10.0, $timeout));
        $plans = [];
        $name = (string) config("database.default", "mysql");
        $database = config("database.connections." . $name);
        $plans["database"] = is_array($database) && ($database["database"] ?? "") !== ""
            ? ["type" => (string) ($database["driver"] ?? "mysql"), "config" => array_intersect_key($database,
                array_flip(["host", "port", "database", "username", "password", "ssl"]))]
            : null;
        $cache = (string) config("cache.default", "");
        $plans["cache"] = $cache === "redis"
            ? ["type" => "redis", "config" => (array) config("cache.stores.redis", [])]
            : ($cache === "file" ? ["type" => "directory", "config" => [
                "path" => (string) config("cache.stores.file.path", storage_path("framework/cache"))]]
                : ($cache === "" ? null : ["type" => $cache, "config" => []]));
        $queue = (string) config("queue.default", "");
        $plans["queue"] = $queue === "redis"
            ? ["type" => "redis", "config" => (array) config("queue.connections.redis", [])]
            : ($queue === "file" ? ["type" => "directory", "config" => [
                "path" => storage_path((string) config("queue.connections.file.path", "framework/queue"))]]
                : ($queue === "" ? null : ["type" => $queue, "config" => []]));
        $checks = [];
        foreach ($plans as $service => $plan) {
            $started = hrtime(true);
            if ($plan === null) {
                $result = ["status" => "not_configured", "code" => "no_supported_configuration"];
            } elseif (!in_array($plan["type"], ["mysql", "redis", "directory"], true)) {
                $result = ["status" => "unsupported", "code" => "custom_adapter_requires_probe"];
            } else {
                try {
                    if ($plan["type"] === "redis") {
                        $plan["config"] = array_intersect_key($plan["config"], array_flip(["host", "port", "database", "password"]));
                    }
                    $process = BoundedPhpProcess::run(__DIR__ . "/runtime-probe.php",
                        [...$plan, "timeout" => (int) ceil($timeout)], $timeout);
                    $result = $process["status"] === "completed" && $process["exit_code"] === 0
                        ? json_decode($process["output"], true, 8, JSON_THROW_ON_ERROR)
                        : ["status" => $process["status"] === "completed" ? "error" : $process["status"], "code" => "probe_incomplete"];
                    if (!is_array($result) || !in_array($result["status"] ?? null, ["ready", "error", "unavailable", "timeout", "output_limit"], true)) {
                        $result = ["status" => "error", "code" => "invalid_probe_result"];
                    }
                } catch (Throwable) {
                    $result = ["status" => "error", "code" => "probe_failed"];
                }
            }
            $checks[] = ["service" => $service, ...$result, "duration_ms" => (int) ((hrtime(true) - $started) / 1e6)];
        }
        $failed = array_filter($checks, static fn (array $check): bool => !in_array($check["status"], ["ready", "not_configured"], true));
        $ready = array_filter($checks, static fn (array $check): bool => $check["status"] === "ready");
        return ["schema" => "fnlla.runtime.readiness.v1",
            "status" => $failed !== [] ? "not_ready" : ($ready === [] ? "not_configured" : "ready"),
            "timeout_per_check_seconds" => $timeout, "checks" => $checks,
            "limitations" => ["Read-only probes do not prove write access, migrations, queue delivery, mail or external-provider readiness.",
                "No application provider code is executed by the isolated probes."]];
    }
}
