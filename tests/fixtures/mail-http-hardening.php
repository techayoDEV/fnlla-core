<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';
config_set('mail.default', 'http');
config_set('mail.http.endpoint', $argv[1]);
config_set('mail.http.allowed_hosts', ['127.0.0.1']);
config_set('mail.http.token', 'SYNTHETIC_TEST_TOKEN');
config_set('mail.http.timeout_seconds', 3);
try {
    (new Fnlla\Php\Mail\Mailer())->send('synthetic@example.invalid', 'Test', 'Synthetic body');
    echo 'delivered';
} catch (RuntimeException $error) {
    echo $error->getMessage();
    exit(2);
}
