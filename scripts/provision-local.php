<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ! in_array('--new-local-instance', $argv, true)) {
    exit("Only run this against a newly initialized, dedicated local MySQL instance: php scripts/provision-local.php --new-local-instance\n");
}

$pdo = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
if (str_contains(strtolower($version), 'mariadb') || version_compare($version, '8.0.0', '<')) {
    throw new RuntimeException('MySQL 8.0+ is required.');
}

$appPassword = bin2hex(random_bytes(24));
$testPassword = bin2hex(random_bytes(24));
$rootPassword = bin2hex(random_bytes(32));
foreach (['office_converters' => $appPassword, 'office_converters_test' => $testPassword] as $name => $password) {
    $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("CREATE USER '$name'@'127.0.0.1' IDENTIFIED BY ".$pdo->quote($password));
    $pdo->exec("GRANT ALL PRIVILEGES ON `$name`.* TO '$name'@'127.0.0.1'");
}
$pdo->exec("ALTER USER 'root'@'localhost' IDENTIFIED BY ".$pdo->quote($rootPassword));

$environment = file_get_contents(__DIR__.'/../.env');
$environment = preg_replace('/^DB_PASSWORD=.*$/m', 'DB_PASSWORD='.$appPassword, $environment);
file_put_contents(__DIR__.'/../.env', $environment);
$testing = preg_replace(
    ['/^APP_ENV=.*$/m', '/^DB_DATABASE=.*$/m', '/^DB_USERNAME=.*$/m', '/^DB_PASSWORD=.*$/m'],
    ['APP_ENV=testing', 'DB_DATABASE=office_converters_test', 'DB_USERNAME=office_converters_test', 'DB_PASSWORD='.$testPassword],
    $environment
);
file_put_contents(__DIR__.'/../.env.testing', $testing);
$private = getenv('LOCALAPPDATA').DIRECTORY_SEPARATOR.'OfficeConverters'.DIRECTORY_SEPARATOR.'mysql-root.ini';
file_put_contents($private, "[client]\nhost=127.0.0.1\nport=3307\nuser=root\npassword=$rootPassword\n");
echo "Verified MySQL $version on 127.0.0.1:3307. Created separate application and test databases/users.\n";
echo "Credentials saved locally in ignored environment files; root recovery file: $private\n";
