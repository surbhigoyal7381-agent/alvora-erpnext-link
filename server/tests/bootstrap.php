<?php

// Unit tests need only the package's own autoloader (server_vendor) or, when run inside the AlvoraVector
// api shell, the shell's. Integration tests (server/tests/Integration) also boot the shell application.
$candidates = [__DIR__ . '/../../server_vendor/autoload.php'];
if ($api = getenv('ALVORA_API_PATH')) {
    $candidates[] = rtrim($api, '/') . '/vendor/autoload.php';
}
foreach ($candidates as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        spl_autoload_register(function (string $class) {
            $prefix = 'Alvora\\ErpnextLink\\';
            if (str_starts_with($class, $prefix . 'Tests\\')) {
                $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix . 'Tests\\'))) . '.php';
            } elseif (str_starts_with($class, $prefix)) {
                $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            } else {
                return;
            }
            is_file($file) && require $file;
        });

        return;
    }
}

throw new RuntimeException('No autoloader found: run `composer install` or set ALVORA_API_PATH to the api shell.');
