<?php

declare(strict_types=1);

use Fnlla\Php\Http\Cookie;
use Fnlla\Php\Http\Response;
use Fnlla\Php\Support\FrameworkUpdateTransaction;

// Loaded by the Core suite after its shared bootstrap. No customer state is used.
$boundaryRoot = sys_get_temp_dir() . '/fnlla-boundaries-' . bin2hex(random_bytes(8));
mkdir($boundaryRoot, 0700);
try {
    file_put_contents($boundaryRoot . '/first.php', 'current-first');
    file_put_contents($boundaryRoot . '/second.php', 'current-second');
    $transaction = new FrameworkUpdateTransaction($boundaryRoot);
    $entries = [];
    foreach (['first.php', 'second.php'] as $relative) {
        $backup = hash('sha256', $relative) . '.backup';
        file_put_contents($boundaryRoot . '/.fnlla/update-transaction/' . $backup, 'old-' . $relative);
        $entries[$relative] = ['exists' => true, 'backup' => $backup,
            'hash' => hash('sha256', 'old-' . $relative), 'mode' => 0644];
    }
    $journalPath = $boundaryRoot . '/.fnlla/update-transaction/journal.json';
    $entries['first.php']['mode'] = 'invalid';
    file_put_contents($journalPath, json_encode(['schema' => 'fnlla.update_transaction.v1', 'entries' => $entries]));
    try { $transaction->recover(); throw new LogicException('Damaged mode was accepted.'); }
    catch (RuntimeException) { /* All metadata must be checked before any restoration. */ }
    assert_same('current-first', file_get_contents($boundaryRoot . '/first.php'), 'Invalid metadata changed the first file.');
    assert_same('current-second', file_get_contents($boundaryRoot . '/second.php'), 'Invalid metadata caused a partial restore.');
    assert_true(is_file($journalPath), 'Invalid journal must be retained for operator recovery.');
    $entries['first.php']['mode'] = 0644;
    file_put_contents($journalPath, json_encode(['schema' => 'fnlla.update_transaction.v1', 'entries' => $entries]));
    $transaction->recover();
    assert_same('old-first.php', file_get_contents($boundaryRoot . '/first.php'), 'Corrected recovery did not restore the first file.');
    assert_same('old-second.php', file_get_contents($boundaryRoot . '/second.php'), 'Corrected recovery did not restore the second file.');
    foreach (['.fnlla/update-transaction/journal.json', '.fnlla/update-transaction/lock'] as $relative) {
        $invoked = false;
        try { $transaction->run([$relative], static function () use (&$invoked): void { $invoked = true; }); throw new LogicException('Reserved path was accepted.'); }
        catch (RuntimeException) { assert_true(!is_file($journalPath), 'Reserved path created a journal.'); }
        assert_true(!$invoked, 'Reserved path reached installation.');
    }
    unset($transaction);

    // Case-insensitive Windows paths must not alias protected application state.
    file_put_contents($boundaryRoot . '/.env', 'synthetic-only');
    $transaction = new FrameworkUpdateTransaction($boundaryRoot);
    foreach (['.ENV', 'Storage/customer.json', 'Public/Uploads/avatar.png', '.GIT/config',
        '.fnlla/update-transaction./journal.json', 'config/NUL.php'] as $relative) {
        $invoked = false;
        try { $transaction->run([$relative], static function () use (&$invoked): void { $invoked = true; }); throw new LogicException('Unsafe alias was accepted: ' . $relative); }
        catch (RuntimeException) { assert_true(!is_file($journalPath), 'Unsafe alias created a journal: ' . $relative); }
        assert_true(!$invoked, 'Unsafe alias reached installation: ' . $relative);
    }
    assert_same('synthetic-only', file_get_contents($boundaryRoot . '/.env'), 'Protected environment contents changed.');
    unset($transaction);

    foreach ([new Cookie('id', 'value', path: '/; SameSite=None'),
        new Cookie('id', 'value', domain: 'example.test; Secure'),
        new Cookie('id', 'value', sameSite: 'Lax; Secure')] as $cookie) {
        try { $cookie->toHeaderValue(); throw new LogicException('Cookie attribute injection accepted.'); }
        catch (InvalidArgumentException) { /* Unsafe attributes cannot become another cookie directive. */ }
    }
    assert_true(str_contains((new Cookie('id', '=;\r\n', secure: true))->toHeaderValue(), '%3D%3B'), 'Cookie values must remain encoded.');
    assert_true(str_contains((new Cookie('id', 'ok', sameSite: 'Strict'))->toHeaderValue(), 'SameSite=Strict'), 'Valid cookie behavior changed.');
    $savedHeaders = config('http.security_headers', []);
    try {
        config_set('http.security_headers', ['Content-Security-Policy' => "default-src 'self'"]);
        $headers = Response::html('')->withHeader('content-security-policy', "default-src 'none'")->headers();
        assert_true(!isset($headers['Content-Security-Policy']), 'Response duplicated a default header with different casing.');
        assert_same("default-src 'none'", $headers['content-security-policy'], 'Explicit header did not override the default.');
    } finally { config_set('http.security_headers', $savedHeaders); }
    echo "[PASS] Enterprise boundaries: preflight recovery, reserved paths, cookies and headers.\n";
} finally {
    unset($transaction);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($boundaryRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) { $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    rmdir($boundaryRoot);
}
