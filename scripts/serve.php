<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';

$server = new Process([
    PHP_BINARY, '-d', 'upload_max_filesize=6M', '-d', 'post_max_size=32M',
    '-d', 'memory_limit=256M', '-S', '127.0.0.1:8000',
    realpath(__DIR__.'/../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'),
], realpath(__DIR__.'/../public'));
$server->setTimeout(null);
exit($server->run(fn ($type, $output) => print $output));
