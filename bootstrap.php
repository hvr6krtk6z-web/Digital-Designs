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

function branding_types(): array
{
	return [
		'favicon' => ['setting' => 'favicon_file', 'max' => 524288, 'mimes' => ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico']],
		'logo' => ['setting' => 'logo_file', 'max' => 1048576, 'mimes' => ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp']],
	];
}

function branding_dir(): string
{
	return app_storage_path() . '/branding';
}

function branding_file(array $settings, string $kind): string
{
	$name = (string) ($settings[branding_types()[$kind]['setting']] ?? '');
	return preg_match('/^(favicon|logo)-[a-f0-9]{12}\.(png|jpg|webp|ico)$/', $name) && is_file(branding_dir() . '/' . $name) ? $name : '';
}

function branding_url(array $settings, string $kind): string
{
	$name = branding_file($settings, $kind);
	if ($name === '') {
		return $kind === 'favicon' ? '/favicon.svg' : '';
	}
	return '/brand/' . $kind . '?v=' . substr($name, strlen($kind) + 1, 12);
}

function branding_head(array $settings): string
{
	return '<link rel="icon" href="' . h(branding_url($settings, 'favicon')) . '">';
}

function branding_mark(array $settings, string $class, string $fallback = 'F/'): string
{
	$url = branding_url($settings, 'logo');
	return $url === ''
		? '<span class="' . $class . '" aria-hidden="true">' . $fallback . '</span>'
		: '<img class="' . $class . ' brand-logo" src="' . h($url) . '" alt="" width="38" height="38">';
}

function branding_remove(PDO $db, string $kind): void
{
	$settings = setting_values($db);
	$name = branding_file($settings, $kind);
	if ($name !== '') {
		@unlink(branding_dir() . '/' . $name);
	}
	$db->prepare('INSERT INTO settings (name, value) VALUES (:name, "") ON CONFLICT(name) DO UPDATE SET value = ""')->execute(['name' => branding_types()[$kind]['setting']]);
}

function branding_save_upload(PDO $db, string $kind, array $upload): ?string
{
	$type = branding_types()[$kind];
	$label = $kind === 'favicon' ? 'Favicon' : 'Logo';
	if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
		return null;
	}
	if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
		return $label . ' upload failed. Try a smaller file.';
	}
	if ((int) $upload['size'] > $type['max'] || (int) $upload['size'] === 0) {
		return $label . ' must be between 1 byte and ' . ($type['max'] / 1048576 >= 1 ? '1 MB.' : '512 KB.');
	}
	$mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $upload['tmp_name']);
	$extension = $type['mimes'][$mime] ?? null;
	if ($extension === null || ($extension !== 'ico' && !@getimagesize((string) $upload['tmp_name']))) {
		return $label . ' must be a ' . ($kind === 'favicon' ? 'PNG, JPG, WebP or ICO' : 'PNG, JPG or WebP') . ' image.';
	}
	$dir = branding_dir();
	if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
		return 'Unable to create the branding storage directory.';
	}
	$name = $kind . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
	if (!move_uploaded_file((string) $upload['tmp_name'], $dir . '/' . $name)) {
		return $label . ' could not be saved.';
	}
	chmod($dir . '/' . $name, 0600);
	branding_remove($db, $kind);
	$db->prepare('INSERT INTO settings (name, value) VALUES (:name, :value) ON CONFLICT(name) DO UPDATE SET value = excluded.value')->execute(['name' => $type['setting'], 'value' => $name]);
	return null;
}