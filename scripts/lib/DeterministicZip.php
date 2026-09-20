<?php

declare(strict_types=1);

final class DeterministicZip
{
    /** @param array<string, string> $entries Archive path => file bytes. */
    public static function create(string $archivePath, array $entries): void
    {
        if ($entries === [] || count($entries) > 65535) {
            throw new RuntimeException("ZIP entry count is outside the supported range.");
        }
        if (file_exists($archivePath)) {
            throw new RuntimeException("Archive already exists: " . $archivePath);
        }
        if (!is_dir(dirname($archivePath))
            && !mkdir(dirname($archivePath), 0755, true)
            && !is_dir(dirname($archivePath))) {
            throw new RuntimeException("Cannot create archive directory.");
        }

        ksort($entries, SORT_STRING);
        $body = "";
        $central = "";
        foreach ($entries as $name => $contents) {
            self::assertPath($name);
            $size = strlen($contents);
            if ($size > 0xffffffff) {
                throw new RuntimeException("ZIP64 is not supported: " . $name);
            }
            $offset = strlen($body);
            $crc = (int) hexdec(hash("crc32b", $contents));
            $nameLength = strlen($name);
            $body .= pack(
                "VvvvvvVVVvv",
                0x04034b50,
                20,
                0x0800,
                0,
                0,
                0x0021,
                $crc,
                $size,
                $size,
                $nameLength,
                0
            ) . $name . $contents;
            $central .= pack(
                "VvvvvvvVVVvvvvvVV",
                0x02014b50,
                0x0314,
                20,
                0x0800,
                0,
                0,
                0x0021,
                $crc,
                $size,
                $size,
                $nameLength,
                0,
                0,
                0,
                0,
                0100644 << 16,
                $offset
            ) . $name;
        }

        $count = count($entries);
        $end = pack(
            "VvvvvVVv",
            0x06054b50,
            0,
            0,
            $count,
            $count,
            strlen($central),
            strlen($body),
            0
        );
        $bytes = $body . $central . $end;
        if (file_put_contents($archivePath, $bytes) !== strlen($bytes)) {
            throw new RuntimeException("Cannot write deterministic ZIP archive.");
        }
    }

    private static function assertPath(string $path): void
    {
        if ($path === ""
            || str_contains($path, "\\")
            || str_contains($path, "../")
            || str_starts_with($path, "/")
            || preg_match('/^[A-Za-z]:/', $path) === 1) {
            throw new RuntimeException("Unsafe ZIP entry path: " . $path);
        }
    }
}
