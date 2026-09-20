<?php

declare(strict_types=1);

namespace Fnlla\Php\Tenancy;

use Fnlla\Php\Filesystem\FilesystemAdapter;
use Fnlla\Php\Http\UploadedFile;

final class TenantFilesystem
{
    public function __construct(private FilesystemAdapter $disk, private TenantResourceScope $scope)
    {
    }

    public function put(string $path, string $contents): bool { return $this->disk->put($this->scope->filePath($path), $contents); }
    public function putFile(string $directory, UploadedFile $file, ?string $name = null): string { return $this->disk->putFile($this->scope->filePath($directory), $file, $name); }
    public function delete(string $path): bool { return $this->disk->delete($this->scope->filePath($path)); }
    public function exists(string $path): bool { return $this->disk->exists($this->scope->filePath($path)); }
    public function path(string $path): string { return $this->disk->path($this->scope->filePath($path)); }
    public function url(string $path): string { return $this->disk->url($this->scope->filePath($path)); }
}
