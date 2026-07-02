<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CircuitMap\Models\UserRepository;
use CircuitMap\Support\Database;
use CircuitMap\Support\Env;

$users = new UserRepository(Database::connection());

if ($users->countAll() > 0) {
    fwrite(STDOUT, "Users already exist, skipping initial admin bootstrap.\n");
    exit(0);
}

$email = Env::get('INITIAL_ADMIN_EMAIL');
$password = Env::get('INITIAL_ADMIN_PASSWORD');

if ($email === null || $password === null) {
    fwrite(STDERR, "No users exist and INITIAL_ADMIN_EMAIL/INITIAL_ADMIN_PASSWORD are not set. Cannot bootstrap.\n");
    exit(1);
}

if (strlen($password) < 12) {
    fwrite(STDERR, "INITIAL_ADMIN_PASSWORD must be at least 12 characters.\n");
    exit(1);
}

$users->create($email, password_hash($password, PASSWORD_DEFAULT), 'admin', 'Administrator');
fwrite(STDOUT, "Created initial admin account: {$email}\n");
