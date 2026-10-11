<?php
declare(strict_types=1);

function app_db(): PDO
{
	static $db;
	if ($db instanceof PDO) {
		return $db;
	}

	$storage = app_storage_path();
	if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
		throw new RuntimeException('Unable to create private storage directory.');
	}

	$databasePath = $storage . '/site.sqlite';
	$db = new PDO('sqlite:' . $databasePath);
	chmod($databasePath, 0600);
	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$db->exec('CREATE TABLE IF NOT EXISTS admins (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		username TEXT NOT NULL UNIQUE,
		password_hash TEXT NOT NULL
	)');
	$db->exec('CREATE TABLE IF NOT EXISTS settings (
		name TEXT PRIMARY KEY,
		value TEXT NOT NULL
	)');
	$db->exec('CREATE TABLE IF NOT EXISTS login_attempts (
		ip_hash TEXT PRIMARY KEY,
		attempts INTEGER NOT NULL,
		updated_at INTEGER NOT NULL
	)');

	$defaults = [
		'title' => 'Fieldnote Basic | Independent creative studio',
		'description' => 'Fieldnote Basic is an independent creative studio for thoughtful brands and digital experiences.',
		'canonical_url' => '',
		'social_title' => 'Fieldnote Basic | Independent creative studio',
		'social_description' => 'Thoughtful brands and digital experiences for people doing things differently.',
		'social_image' => '',
		'indexing_enabled' => '0',
	];
	$insert = $db->prepare('INSERT OR IGNORE INTO settings (name, value) VALUES (:name, :value)');
	foreach ($defaults as $name => $value) {
		$insert->execute(['name' => $name, 'value' => $value]);
	}
	$db->exec("UPDATE settings SET value = replace(value, 'Fieldnote Studio', 'Fieldnote Basic') WHERE name IN ('title', 'description', 'social_title') AND instr(value, 'Fieldnote Studio') > 0");
	if ((int) $db->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0) {
		$tokenPath = $storage . '/initial-setup-token';
		$tokenFile = @fopen($tokenPath, 'x');
		if ($tokenFile) {
			fwrite($tokenFile, bin2hex(random_bytes(32)));
			fclose($tokenFile);
			chmod($tokenPath, 0600);
		}
	}

	return $db;
}

function app_storage_path(): string
{
	return getenv('APP_STORAGE_PATH') ?: __DIR__ . '/storage';
}

function setting_values(PDO $db): array
{
	return $db->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
}

function h(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
	if (empty($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['csrf_token'];
}

function valid_csrf(): bool
{
	return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
		&& hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token']);
}

function admin_session_start(): void
{
	if (session_status() === PHP_SESSION_ACTIVE) {
		return;
	}
	ini_set('session.use_strict_mode', '1');
	session_set_cookie_params([
		'httponly' => true,
		'samesite' => 'Strict',
		'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
		'path' => '/',
	]);
	session_start();
}