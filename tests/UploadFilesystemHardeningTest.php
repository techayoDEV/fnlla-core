<?php

declare(strict_types=1);

use Fnlla\Php\Filesystem\FilesystemAdapter;
use Fnlla\Php\Http\UploadedFile;

$fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "fnlla-core-upload-hardening-" . bin2hex(random_bytes(6));
$diskRoot = $fixtureRoot . DIRECTORY_SEPARATOR . "disk";
$outsideRoot = $fixtureRoot . DIRECTORY_SEPARATOR . "outside";
mkdir($outsideRoot, 0700, true);
$allowedTypes = config("security.uploads.allowed_mime_types");
config_set("security.uploads.allowed_mime_types", ["application/pdf"]);

try {
    $disk = new FilesystemAdapter($diskRoot);
    $source = $fixtureRoot . DIRECTORY_SEPARATOR . "payload.tmp";
    $bytes = "%PDF-1.4\n<?php /* inert upload fixture */\n";
    file_put_contents($source, $bytes);
    $upload = new UploadedFile($source, "report.php", "application/pdf", strlen($bytes), UPLOAD_ERR_OK);
    $stored = $disk->putFile("uploads", $upload);
    assert_true(str_ends_with($stored, ".pdf"), "Upload kept a client-supplied PHP extension.");
    assert_same($bytes, file_get_contents($disk->path($stored)), "Validated upload bytes changed during storage.");

    file_put_contents($source, $bytes);
    $upload = new UploadedFile($source, "report.pdf", "application/pdf", strlen($bytes), UPLOAD_ERR_OK);
    expect_exception(RuntimeException::class,
        static fn (): string => $disk->putFile("uploads", $upload, "report.php"),
        "Explicit executable upload name was accepted.");
    expect_exception(RuntimeException::class,
        static fn (): string => $disk->putFile("uploads", $upload, "report.php.pdf"),
        "Multi-extension upload name was accepted.");
    assert_true(is_file($source), "Rejected upload was moved before validation.");
    assert_same("uploads/report.pdf", $disk->putFile("uploads", $upload, "report.pdf"),
        "Safe explicit upload name was rejected.");

    $wrongBytes = "<html>active content</html>";
    file_put_contents($source, $wrongBytes);
    $wrongMime = new UploadedFile($source, "report.pdf", "application/pdf", strlen($wrongBytes), UPLOAD_ERR_OK);
    expect_exception(RuntimeException::class,
        static fn (): string => $disk->putFile("uploads", $wrongMime),
        "Direct disk upload skipped byte-based MIME validation.");
    assert_true(is_file($source), "Rejected MIME payload was moved.");

    $link = $diskRoot . DIRECTORY_SEPARATOR . "escape";
    if (function_exists("symlink") && @symlink($outsideRoot, $link)) {
        expect_exception(RuntimeException::class,
            static fn (): bool => $disk->put("escape/escaped.txt", "outside"),
            "Filesystem disk followed a symlink outside its root.");
        assert_true(!is_file($outsideRoot . DIRECTORY_SEPARATOR . "escaped.txt"),
            "Filesystem disk wrote outside its root.");
    } else {
        if (getenv("FNLLA_REQUIRE_SYMLINK_TESTS") === "1") {
            throw new RuntimeException("Required symlink escape fixture could not be created.");
        }
        fwrite(STDOUT, "SKIP: symlink escape test (symlink creation unavailable)." . PHP_EOL);
    }
    $rootLink = $fixtureRoot . DIRECTORY_SEPARATOR . "disk-link";
    if (function_exists("symlink") && @symlink($diskRoot, $rootLink)) {
        $linkedDisk = new FilesystemAdapter($rootLink);
        assert_true($linkedDisk->put("linked-root.txt", "allowed"),
            "Configured disk-root symlink was rejected.");
        assert_same("allowed", file_get_contents($diskRoot . DIRECTORY_SEPARATOR . "linked-root.txt"),
            "Configured disk-root symlink did not resolve to the intended disk.");
    } else {
        if (getenv("FNLLA_REQUIRE_SYMLINK_TESTS") === "1") {
            throw new RuntimeException("Required disk-root symlink fixture could not be created.");
        }
        fwrite(STDOUT, "SKIP: configured root symlink test (symlink creation unavailable)." . PHP_EOL);
    }

    $router = dirname(__DIR__) . "/resources/project-templates/core/public/router.php";
    foreach (["/uploads/legacy.php.jpg", "/Uploads/legacy.php.jpg", "/uploads/legacy.php/extra"] as $uri) {
        $probe = '$_SERVER["REQUEST_URI"] = ' . var_export($uri, true) . '; '
            . 'require ' . var_export($router, true) . '; '
            . 'echo "|" . http_response_code();';
        [$routerExit, $routerOutput] = run_process([PHP_BINARY, "-r", $probe], $fixtureRoot);
        assert_same(0, $routerExit, "Core upload router probe failed.");
        assert_same("Not Found|404", trim($routerOutput),
            "Core development router delegated an executable upload path.");
    }
} finally {
    config_set("security.uploads.allowed_mime_types", $allowedTypes);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixtureRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($fixtureRoot);
}

fwrite(STDOUT, "FNLLA upload and filesystem hardening tests passed." . PHP_EOL);
