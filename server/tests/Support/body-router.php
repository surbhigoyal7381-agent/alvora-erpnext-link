<?php

// Router for `php -S` in OutboundGuardTest: /big streams 2 MB with no length (a hostile site), /small is a normal answer.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if (str_ends_with($path, '/big')) {
    for ($i = 0; $i < 2048; $i++) {
        echo str_repeat('a', 1024);
        flush();
    }

    return;
}
echo '{"message":{"ok":true}}';
