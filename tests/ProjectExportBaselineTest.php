<?php

declare(strict_types=1);

use Fnlla\Php\Support\ProjectExportBaseline;

$baselineRoot = sys_get_temp_dir() . '/fnlla-export-baseline-' . bin2hex(random_bytes(6));
mkdir($baselineRoot, 0700);
try {
    file_put_contents($baselineRoot . '/package.json', "{\r\n  \"private\": true\r\n}\r\n");
    file_put_contents($baselineRoot . '/.gitignore', "/vendor/\n/.fnlla/application-update/\n");
    ProjectExportBaseline::record($baselineRoot, ['package.json']);
    ProjectExportBaseline::record($baselineRoot, ['.gitignore']);
    assert_true(ProjectExportBaseline::matches($baselineRoot, 'package.json'), 'Full adaptation lost existing baseline entries.');
    file_put_contents($baselineRoot . '/package.json', "{\n  \"private\": true\n}\n");
    assert_true(ProjectExportBaseline::matches($baselineRoot, 'package.json'), 'EOL-only edits should remain pristine.');
    file_put_contents($baselineRoot . '/package.json', "{\n  \"private\": false\n}\n");
    assert_true(!ProjectExportBaseline::matches($baselineRoot, 'package.json'), 'A real application edit must remain protected.');
    assert_true(!ProjectExportBaseline::equivalent('asset.bin', "a\r\n", "a\n"), 'Binary content must use exact comparison.');
    $boundaryText = str_repeat('a', 65535) . "\r\n" . str_repeat('b', 65535) . "\r";
    file_put_contents($baselineRoot . '/boundary.md', $boundaryText);
    ProjectExportBaseline::record($baselineRoot, ['boundary.md']);
    file_put_contents($baselineRoot . '/normalized.md', str_replace("\r\n", "\n", $boundaryText));
    assert_true(ProjectExportBaseline::equivalentFiles('boundary.md', $baselineRoot . '/boundary.md', $baselineRoot . '/normalized.md'), 'A split CRLF must normalize across stream chunks.');
    file_put_contents($baselineRoot . '/boundary.md', str_replace("\r\n", "\n", $boundaryText));
    assert_true(ProjectExportBaseline::matches($baselineRoot, 'boundary.md'), 'Streaming receipt hash changed across EOL normalization.');
    assert_true(!ProjectExportBaseline::equivalentFiles('asset.bin', $baselineRoot . '/normalized.md', $baselineRoot . '/.gitignore'), 'Binary file comparison must remain exact.');
    foreach (['../escape', 'file:stream', '/absolute'] as $unsafe) {
        try { ProjectExportBaseline::record($baselineRoot, [$unsafe]); throw new LogicException('Unsafe baseline path accepted.'); }
        catch (RuntimeException) {}
    }
    file_put_contents($baselineRoot . '/' . ProjectExportBaseline::PATH, '{broken');
    try { ProjectExportBaseline::matches($baselineRoot, '.gitignore'); throw new LogicException('Corrupt baseline accepted.'); }
    catch (JsonException) {}
} finally {
    foreach (glob($baselineRoot . '/.fnlla/*') ?: [] as $file) { unlink($file); }
    rmdir($baselineRoot . '/.fnlla');
    unlink($baselineRoot . '/package.json');
    unlink($baselineRoot . '/.gitignore');
    unlink($baselineRoot . '/boundary.md');
    unlink($baselineRoot . '/normalized.md');
    rmdir($baselineRoot);
}
echo "Project export baseline preserves edits and normalizes only declared text files.\n";
