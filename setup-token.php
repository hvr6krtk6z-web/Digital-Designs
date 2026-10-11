<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit;
}

require __DIR__ . '/bootstrap.php';
$db = app_db();
if ((int) $db->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0) {
	fwrite(STDERR, "An admin account is already configured.\n");
	exit(1);
}

$tokenPath = app_storage_path() . '/initial-setup-token';
$token = is_file($tokenPath) ? trim((string) file_get_contents($tokenPath)) : '';
if ($token === '') {
	fwrite(STDERR, "Unable to read the setup token. Check the private storage directory.\n");
	exit(1);
}

echo $token . PHP_EOL;