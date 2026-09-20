<?php

declare(strict_types=1);

use Fnlla\Php\Audit\AuditEvent;
use Fnlla\Php\Audit\JsonAuditLogger;
use Fnlla\Php\Auth\AuthManager;
use Fnlla\Php\Auth\Authorization\AccessControl;
use Fnlla\Php\Auth\Authorization\AuthorizationException;
use Fnlla\Php\Auth\Authorization\Gate;
use Fnlla\Php\Auth\Authorization\OwnershipPolicy;
use Fnlla\Php\Auth\Authorization\PolicyRegistry;
use Fnlla\Php\Auth\Authorization\RoleAssignmentGuard;
use Fnlla\Php\Auth\UserProviderInterface;
use Fnlla\Php\Cache\FileCacheStore;
use Fnlla\Php\Container\Container;
use Fnlla\Php\Filesystem\FilesystemAdapter;
use Fnlla\Php\Hashing\Hasher;
use Fnlla\Php\Http\Request;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Queue\FileQueueStore;
use Fnlla\Php\Queue\JobContext;
use Fnlla\Php\Queue\JobEnvelope;
use Fnlla\Php\Queue\QueueManager;
use Fnlla\Php\Session\SessionStore;
use Fnlla\Php\Tenancy\ResolveTenantContext;
use Fnlla\Php\Tenancy\TenantCacheStore;
use Fnlla\Php\Tenancy\TenantContextManager;
use Fnlla\Php\Tenancy\TenantFilesystem;
use Fnlla\Php\Tenancy\TenantResourceScope;
use Fnlla\Php\Tenancy\UserProviderTenantIdentityResolver;

final class SecurityFixtureUsers implements UserProviderInterface
{
    /** @param array<string, array<string, mixed>> $users */
    public function __construct(public array $users) {}
    public function findById(string|int $id): ?array { return $this->users[(string) $id] ?? null; }
    public function findByCredentials(array $credentials): ?array { return null; }
}

final class SecurityTenantJob
{
    public function __construct(private string $recordTenantId) {}
    public function handle(TenantResourceScope $scope, JobContext $job): void
    {
        $scope->assertRecord(["tenant_id" => $this->recordTenantId]);
        $job->assertLeaseOwned();
    }
}

$securityDirectory = sys_get_temp_dir() . "/fnlla-security-primitives-" . bin2hex(random_bytes(6));
if (!mkdir($securityDirectory, 0700, true)) {
    fwrite(STDERR, "Unable to create security fixture directory." . PHP_EOL);
    exit(1);
}

try {
    config_set("auth.session_key", "auth.user_id");
    config_set("auth.providers.users.key", "id");
    config_set("security.authorization", [
        "role_field" => "role",
        "roles" => [
            "developer" => ["permissions" => ["records.read", "records.write", "tenancy.bypass"]],
            "client" => ["permissions" => ["records.read"]],
            "sales-administrator" => ["permissions" => ["sales.manage", "roles.assign"]],
        ],
        "assignable_roles" => ["developer", "client", "sales-administrator"],
        "legacy_gate_permissions" => ["legacy.records" => "records.read"],
    ]);
    config_set("security.tenancy", [
        "mode" => "organization",
        "organization_field" => "organization_id",
        "custom_field" => "tenant_id",
        "additional_field" => "tenant_ids",
    ]);
    config_set("queue.job_types", ["security.tenant" => ["class" => SecurityTenantJob::class, "version" => JobEnvelope::VERSION]]);
    config_set("queue.max_attempts", 1);
    config_set("queue.visibility_timeout_seconds", 30);
    config_set("queue.idempotency_ttl_seconds", 60);
    $_SESSION = [];

    $users = new SecurityFixtureUsers([
        "dev-a" => ["id" => "dev-a", "role" => "developer", "organization_id" => "tenant-a", "tenant_id" => "custom-a", "active" => true],
        "client-b" => ["id" => "client-b", "role" => "client", "organization_id" => "tenant-b", "active" => true],
        "sales" => ["id" => "sales", "role" => "sales-administrator", "organization_id" => "sales-tenant", "active" => true],
        "missing" => ["id" => "missing", "role" => "client", "active" => true],
        "revoked" => ["id" => "revoked", "role" => "developer", "organization_id" => "tenant-a", "active" => false],
    ]);
    $auth = new AuthManager(new SessionStore(), $users, new Hasher());
    $policies = new PolicyRegistry();
    $policies->define("record", new OwnershipPolicy());
    $access = new AccessControl($auth, $policies);
    $auditPath = $securityDirectory . "/audit.jsonl";
    $audit = new JsonAuditLogger($auditPath, ["status", "role", "reason", "outcome", "password"], 30, 50);
    $contexts = new TenantContextManager($auth, new UserProviderTenantIdentityResolver($users), $access, $audit);
    $scope = new TenantResourceScope($contexts);

    $auth->login($users->users["dev-a"]);
    sec_assert_true($access->allows("records.read"), "Developer permission was not granted.");
    sec_assert_true(!$access->allows("sales.manage"), "Developer inherited the sales-administrator role.");
    sec_assert_true(!$access->allows("undefined.permission"), "Undefined permission did not fail closed.");
    sec_assert_true(!$access->allows("records.read", ["__type" => "record", "owner_id" => "client-b", "tenant_id" => "tenant-a"]), "Ownership policy accepted another owner.");
    $contexts->runForAuthenticated(function ($tenant) use ($access): void {
        sec_assert_true($access->allows("records.read", ["__type" => "record", "owner_id" => "dev-a", "tenant_id" => "tenant-a"], null, $tenant), "Owned resource policy rejected the owner.");
        sec_assert_true(!$access->allows("records.read", ["__type" => "record", "owner_id" => "dev-a", "tenant_id" => "tenant-b"], null, $tenant), "Owned resource policy ignored tenant ownership.");
    });

    $gate = new Gate(new Container(), $auth, $access);
    sec_assert_true($gate->allows("legacy.records"), "Legacy Gate permission map did not preserve the mapped ability.");
    sec_assert_true(!$gate->allows("legacy.undefined"), "Unmapped legacy Gate ability did not fail closed.");

    $request = Request::capture("", ["REQUEST_METHOD" => "GET", "REQUEST_URI" => "/records", "HTTP_ACCEPT" => "application/json"]);
    $middleware = new ResolveTenantContext($contexts);
    $allowedResponse = $middleware->handle($request, function () use ($scope): Response {
        $scope->assertRecord(["tenant_id" => "tenant-a"]);
        return Response::text("ok");
    });
    sec_assert_same(200, $allowedResponse->status(), "Tenant A HTTP access was rejected.");
    $deniedResponse = $middleware->handle($request, function () use ($scope): Response {
        $scope->assertRecord(["tenant_id" => "tenant-b"]);
        return Response::text("leaked");
    });
    sec_assert_same(403, $deniedResponse->status(), "Tenant A reached tenant B through HTTP.");
    sec_assert_same(null, $contexts->current(), "HTTP tenant context was not reset.");

    $cache = new TenantCacheStore(new FileCacheStore($securityDirectory . "/cache"), $scope);
    $disk = new TenantFilesystem(new FilesystemAdapter($securityDirectory . "/files"), $scope);
    $contexts->runForAuthenticated(function () use ($cache, $disk, $scope): void {
        $criteria = $scope->repositoryCriteria(["status" => "open"]);
        sec_assert_same("tenant-a", $criteria["tenant_id"] ?? null, "Repository criteria lacks authoritative tenant scope.");
        $cache->put("record", "alpha");
        $disk->put("record.txt", "alpha");
        sec_assert_same("alpha", (string) file_get_contents($disk->path("record.txt")), "Tenant A file write failed.");
        sec_assert_same([["tenant_id" => "tenant-a", "id" => 1]], $scope->exportRecords([["tenant_id" => "tenant-a", "id" => 1]]), "Tenant A export failed.");
        sec_assert_throws(fn () => $scope->exportRecords([["tenant_id" => "tenant-b", "id" => 2]]), AuthorizationException::class, "Cross-tenant export was allowed.");
        sec_assert_throws(fn () => $scope->toolArguments(["tenant_id" => "tenant-b"]), AuthorizationException::class, "Tool accepted a payload-selected tenant.");
    });

    $auth->login($users->users["client-b"]);
    $contexts->runForAuthenticated(function () use ($cache, $disk): void {
        sec_assert_same("missing", $cache->get("record", "missing"), "Tenant B read tenant A cache data.");
        sec_assert_true(!$disk->exists("record.txt"), "Tenant B read tenant A file data.");
        $cache->put("record", "beta");
        $disk->put("record.txt", "beta");
    });
    sec_assert_throws(fn () => $contexts->runBypass("tenant-a", "support review", static fn (): bool => true, "http"), AuthorizationException::class, "Client obtained tenant bypass.");

    $auth->login($users->users["dev-a"]);
    $contexts->runForAuthenticated(function () use ($cache, $disk): void {
        sec_assert_same("alpha", $cache->get("record"), "Tenant B changed tenant A cache data.");
        sec_assert_same("alpha", (string) file_get_contents($disk->path("record.txt")), "Tenant B changed tenant A file data.");
    });
    $contexts->runBypass("tenant-b", "incident-review", function () use ($scope): void {
        $scope->assertRecord(["tenant_id" => "tenant-b"]);
    }, "cli");
    sec_assert_same(null, $contexts->current(), "Bypass tenant context was not reset.");

    $auth->login($users->users["missing"]);
    sec_assert_throws(fn () => $contexts->runForAuthenticated(static fn (): bool => true), AuthorizationException::class, "Missing multi-tenant context was accepted.");
    $auth->login($users->users["revoked"]);
    sec_assert_true(!$access->allows("records.read"), "Revoked actor retained a permission.");
    sec_assert_throws(fn () => $contexts->runForAuthenticated(static fn (): bool => true), AuthorizationException::class, "Revoked actor resolved a tenant.");

    $assignments = new RoleAssignmentGuard($access, $audit);
    $sales = $users->users["sales"];
    sec_assert_throws(fn () => $assignments->authorize($sales, "sales", "developer", "http"), AuthorizationException::class, "Actor self-assigned a role.");
    $assignments->authorize($sales, "client-b", "client", "http");

    $auth->login($users->users["dev-a"]);
    $queueContainer = new Container();
    $queueContainer->instance(TenantResourceScope::class, $scope);
    $queueStore = new FileQueueStore($securityDirectory . "/queue");
    $queue = new QueueManager($queueContainer, $queueStore, null, $contexts);
    $contexts->runForAuthenticated(function () use ($queue): void {
        sec_assert_throws(
            fn () => $queue->push(SecurityTenantJob::class, ["recordTenantId" => "tenant-a"], ["tenant_id" => "tenant-b"]),
            RuntimeException::class,
            "Job payload overrode the server tenant context."
        );
        $queue->push(SecurityTenantJob::class, ["recordTenantId" => "tenant-b"]);
    });
    sec_assert_same(0, $queue->work(1), "Cross-tenant job completed.");
    sec_assert_same(null, $contexts->current(), "Failed job tenant context was not reset.");
    $contexts->runForAuthenticated(fn () => $queue->push(SecurityTenantJob::class, ["recordTenantId" => "tenant-a"]));
    sec_assert_same(1, $queue->work(1), "Authorized tenant job did not complete.");
    sec_assert_same(null, $contexts->current(), "Completed job tenant context was not reset.");

    $contexts->runForAuthenticated(fn () => $queue->push(SecurityTenantJob::class, ["recordTenantId" => "tenant-a"]));
    $users->users["dev-a"]["active"] = false;
    sec_assert_same(0, $queue->work(1), "Job completed after actor access was revoked.");
    sec_assert_same(null, $contexts->current(), "Revoked job left tenant context active.");
    $users->users["dev-a"]["active"] = true;

    config_set("security.tenancy.mode", "custom");
    $auth->login($users->users["dev-a"]);
    $contexts->runForAuthenticated(function ($tenant): void {
        sec_assert_same("custom-a", $tenant->tenantId(), "Custom tenant resolver did not use the server-side configured field.");
    });
    config_set("security.tenancy.mode", "none");
    $scope->assertRecord(["id" => 1]);
    sec_assert_same("tenant:single:key", $scope->cacheKey("key"), "Single-tenant mode did not use the explicit single scope.");

    $audit->record(new AuditEvent("record.updated", "test", "dev-a", "record", "1", "tenant-a", "audit-redaction", [], ["status" => "open", "password" => "super-secret"]));
    $auditContents = (string) file_get_contents($auditPath);
    sec_assert_true(str_contains($auditContents, '"schema":"fnlla.audit-event.v1"'), "Audit schema is absent.");
    sec_assert_true(str_contains($auditContents, '"source":"test"'), "Audit source is absent.");
    sec_assert_true(str_contains($auditContents, '"correlation_id":"audit-redaction"'), "Audit correlation ID is absent.");
    sec_assert_true(!str_contains($auditContents, "super-secret"), "Audit log retained a secret value.");
    sec_assert_true(str_contains($auditContents, "[redacted]"), "Audit adapter did not redact an allowlisted sensitive field.");
} finally {
    sec_remove_directory($securityDirectory);
}

fwrite(STDOUT, "FNLLA security primitives tests passed." . PHP_EOL);

function sec_assert_true(bool $condition, string $message): void
{
    if (!$condition) { sec_fail($message); }
}

function sec_assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) { sec_fail($message . " Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "."); }
}

function sec_assert_throws(callable $callback, string $class, string $message): void
{
    try { $callback(); } catch (Throwable $exception) {
        if ($exception instanceof $class) { return; }
        sec_fail($message . " Wrong exception: " . $exception::class . " - " . $exception->getMessage());
    }
    sec_fail($message);
}

function sec_fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function sec_remove_directory(string $directory): void
{
    if (!is_dir($directory)) { return; }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($directory);
}
