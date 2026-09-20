<?php

declare(strict_types=1);

namespace Fnlla\Php\Tenancy;

use Fnlla\Php\Auth\Authorization\AuthorizationException;

final class TenantResourceScope
{
    public function __construct(private TenantContextManager $contexts)
    {
    }

    /** @return array<string, mixed> */
    public function repositoryCriteria(array $criteria, string $tenantField = "tenant_id"): array
    {
        $context = $this->contexts->requireContext();
        if ($context->mode() === "none") {
            return $criteria;
        }
        $this->assertSuppliedTenant($criteria[$tenantField] ?? null, $context);
        $criteria[$tenantField] = $context->tenantId();
        return $criteria;
    }

    public function assertRecord(array $record, string $tenantField = "tenant_id"): void
    {
        $context = $this->contexts->requireContext();
        if ($context->mode() === "none") {
            return;
        }
        $this->assertSuppliedTenant($record[$tenantField] ?? null, $context, true);
    }

    public function cacheKey(string $key): string
    {
        return "tenant:" . $this->tenantToken() . ":" . $key;
    }

    public function filePath(string $path): string
    {
        return "tenants/" . $this->tenantToken() . "/" . ltrim(str_replace("\\", "/", $path), "/");
    }

    /** @param list<array<string, mixed>> $records
     *  @return list<array<string, mixed>>
     */
    public function exportRecords(array $records, string $tenantField = "tenant_id"): array
    {
        foreach ($records as $record) {
            $this->assertRecord($record, $tenantField);
        }
        return $records;
    }

    /** @return array<string, mixed> */
    public function toolArguments(array $arguments, string $tenantField = "tenant_id"): array
    {
        return $this->repositoryCriteria($arguments, $tenantField);
    }

    private function tenantToken(): string
    {
        $context = $this->contexts->requireContext();
        return $context->mode() === "none"
            ? "single"
            : hash("sha256", (string) $context->tenantId());
    }

    private function assertSuppliedTenant(mixed $supplied, TenantContext $context, bool $required = false): void
    {
        if ($supplied === null && !$required) {
            return;
        }
        if (!is_string($supplied) || !hash_equals((string) $context->tenantId(), $supplied)) {
            throw new AuthorizationException("Cross-tenant resource access is forbidden.");
        }
    }
}
