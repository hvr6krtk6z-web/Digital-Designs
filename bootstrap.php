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
	$db->exec('CREATE TABLE IF NOT EXISTS services (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		name TEXT NOT NULL,
		description TEXT NOT NULL DEFAULT "",
		duration_minutes INTEGER NOT NULL CHECK (duration_minutes BETWEEN 15 AND 480),
		buffer_minutes INTEGER NOT NULL DEFAULT 0 CHECK (buffer_minutes BETWEEN 0 AND 120),
		price_cents INTEGER NOT NULL DEFAULT 0 CHECK (price_cents >= 0),
		active INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
		sort_order INTEGER NOT NULL DEFAULT 0,
		created_at TEXT NOT NULL
	)');
	$db->exec('CREATE TABLE IF NOT EXISTS weekly_hours (
		weekday INTEGER PRIMARY KEY CHECK (weekday BETWEEN 1 AND 7),
		is_open INTEGER NOT NULL DEFAULT 0 CHECK (is_open IN (0, 1)),
		start_time TEXT,
		end_time TEXT,
		CHECK ((is_open = 0) OR (start_time IS NOT NULL AND end_time IS NOT NULL AND start_time < end_time))
	)');
	$db->exec('CREATE TABLE IF NOT EXISTS booking_dates (
		local_date TEXT PRIMARY KEY,
		is_closed INTEGER NOT NULL DEFAULT 1 CHECK (is_closed IN (0, 1)),
		start_time TEXT,
		end_time TEXT,
		note TEXT NOT NULL DEFAULT "",
		CHECK ((is_closed = 1) OR (start_time IS NOT NULL AND end_time IS NOT NULL AND start_time < end_time))
	)');
	$db->exec('CREATE TABLE IF NOT EXISTS page_content (
		content_key TEXT PRIMARY KEY,
		content_value TEXT NOT NULL
	)');
	$db->exec('CREATE TABLE IF NOT EXISTS bookings (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		reference TEXT NOT NULL UNIQUE,
		service_id INTEGER NOT NULL REFERENCES services(id),
		service_name_snapshot TEXT NOT NULL,
		price_cents_snapshot INTEGER NOT NULL DEFAULT 0,
		customer_name TEXT NOT NULL,
		customer_email TEXT NOT NULL,
		customer_phone TEXT NOT NULL DEFAULT "",
		customer_note TEXT NOT NULL DEFAULT "",
		local_date TEXT NOT NULL,
		starts_at_utc TEXT NOT NULL,
		ends_at_utc TEXT NOT NULL,
		blocked_until_utc TEXT NOT NULL,
		timezone TEXT NOT NULL,
		status TEXT NOT NULL DEFAULT "confirmed" CHECK (status IN ("confirmed", "cancelled", "completed", "no_show")),
		created_at TEXT NOT NULL,
		updated_at TEXT NOT NULL
	)');
	$db->exec('CREATE INDEX IF NOT EXISTS bookings_time_idx ON bookings (starts_at_utc, blocked_until_utc, status)');
	$db->exec('CREATE INDEX IF NOT EXISTS bookings_date_idx ON bookings (local_date, status)');
	$db->exec('CREATE TABLE IF NOT EXISTS booking_rate_limits (
		ip_hash TEXT PRIMARY KEY,
		request_count INTEGER NOT NULL,
		window_started_at INTEGER NOT NULL
	)');
	$db->exec('CREATE TABLE IF NOT EXISTS booking_events (
		id INTEGER PRIMARY KEY AUTOINCREMENT,
		booking_id INTEGER NOT NULL REFERENCES bookings(id),
		actor TEXT NOT NULL,
		action TEXT NOT NULL,
		details TEXT NOT NULL DEFAULT "",
		created_at TEXT NOT NULL
	)');

	$defaults = [
		'title' => 'Fieldnote Booking | Creative studio appointments',
		'description' => 'Fieldnote Booking is a creative studio with simple online appointment booking.',
		'canonical_url' => '',
		'social_title' => 'Fieldnote Booking | Creative studio appointments',
		'social_description' => 'Book a creative studio appointment online.',
		'social_image' => '',
		'indexing_enabled' => '0',
		'booking_enabled' => '1',
		'booking_timezone' => 'Europe/London',
		'booking_slot_minutes' => '30',
		'booking_notice_hours' => '4',
		'booking_max_days' => '90',
	];
	$insert = $db->prepare('INSERT OR IGNORE INTO settings (name, value) VALUES (:name, :value)');
	foreach ($defaults as $name => $value) {
		$insert->execute(['name' => $name, 'value' => $value]);
	}
	$pageDefaults = [
		'brand_line_1' => 'Fieldnote',
		'brand_line_2' => 'Booking',
		'hero_eyebrow' => 'Independent creative studio',
		'hero_title_1' => 'Make a mark.',
		'hero_title_2' => 'Mean it.',
		'hero_description' => 'We build thoughtful brands and digital experiences for people doing things differently.',
		'hero_button' => 'Book a session',
		'hero_image' => 'https://images.unsplash.com/photo-1498050108023-c5249f4df085?auto=format&fit=crop&w=2200&q=85',
		'intro_eyebrow' => 'A connected approach',
		'intro_title_1' => 'Clear thinking.',
		'intro_title_2' => 'Good feeling.',
		'intro_description' => 'We partner with ambitious people to turn a little spark into a brand with purpose, a website with character and a story worth sharing.',
		'services_title' => 'Our sessions',
		'services_description' => 'Choose a session and find a time that works for you.',
		'projects_eyebrow' => 'A few good things',
		'projects_title' => 'Selected work',
		'projects_description' => 'A couple of sample projects to show how your work could take centre stage.',
		'project_1_title' => 'Goodwell Botanics',
		'project_1_caption' => 'Sample project · Brand & packaging',
		'project_1_image' => 'https://images.unsplash.com/photo-1608248543803-ba4f8c70ae0b?auto=format&fit=crop&w=1200&q=85',
		'project_2_title' => 'Sunday Standard',
		'project_2_caption' => 'Sample project · Identity & website',
		'project_2_image' => 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?auto=format&fit=crop&w=1200&q=85',
	'contact_eyebrow' => 'Have a good one?',
		'contact_title_1' => "Let's make it",
		'contact_title_2' => 'real.',
		'contact_description' => 'Tell us what you are working on and book a first conversation.',
		'contact_button' => 'Book a session',
		'contact_email' => 'hello@example.com',
		'footer_label' => 'Independent creative studio',
	];
	$insertContent = $db->prepare('INSERT OR IGNORE INTO page_content (content_key, content_value) VALUES (:key, :value)');
	foreach ($pageDefaults as $key => $value) {
		$insertContent->execute(['key' => $key, 'value' => $value]);
	}
	$seeded = $db->prepare('INSERT OR IGNORE INTO settings (name, value) VALUES ("default_service_seeded", "1")');
	$seeded->execute();
	if ($seeded->rowCount() === 1 && (int) $db->query('SELECT COUNT(*) FROM services')->fetchColumn() === 0) {
		$db->exec('INSERT INTO services (name, description, duration_minutes, buffer_minutes, price_cents, active, sort_order, created_at) VALUES ("Studio consultation", "A focused session to discuss your project.", 60, 15, 0, 1, 0, CURRENT_TIMESTAMP)');
	}
	$hours = $db->prepare('INSERT OR IGNORE INTO weekly_hours (weekday, is_open, start_time, end_time) VALUES (:weekday, :is_open, :start_time, :end_time)');
	for ($weekday = 1; $weekday <= 7; $weekday++) {
		$isOpen = $weekday <= 5;
		$hours->execute([
			'weekday' => $weekday,
			'is_open' => $isOpen ? 1 : 0,
			'start_time' => $isOpen ? '09:00' : null,
			'end_time' => $isOpen ? '17:00' : null,
		]);
	}
	$db->exec("UPDATE settings SET value = replace(value, 'Fieldnote Basic', 'Fieldnote Booking') WHERE name IN ('title', 'description', 'social_title') AND instr(value, 'Fieldnote Basic') > 0");
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
	return getenv('APP_STORAGE_PATH') ?: __DIR__ . '/storage/fieldnote-booking';
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
