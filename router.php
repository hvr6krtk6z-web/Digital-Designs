<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$route = rtrim($path, '/') ?: '/';

if (str_starts_with($path, '/storage/') || str_starts_with($path, '/.')) {
	http_response_code(404);
	exit;
}

$routes = ['/','/index.php','/preview.html','/admin','/admin/pages','/admin/bookings','/admin/services','/admin/availability','/book','/api/services','/api/calendar','/api/slots','/api/bookings','/api/admin/slots','/api/admin/bookings','/robots.txt','/sitemap.xml','/favicon.ico','/brand/favicon','/brand/logo'];
if (!in_array($route, $routes, true)) {
	$requested = realpath(__DIR__ . $path);
	if ($requested && str_starts_with($requested, __DIR__ . DIRECTORY_SEPARATOR) && is_file($requested)) {
		return false;
	}
	http_response_code(404);
	echo 'Not found';
	exit;
}

require __DIR__ . '/bootstrap.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: DENY');

require __DIR__ . '/booking.php';

if (str_starts_with($route, '/api/')) {
	booking_api_dispatch(app_db(), $route);
}

if ($route === '/admin') {
	require __DIR__ . '/admin.php';
	exit;
}

$db = app_db();
$settings = setting_values($db);

if ($route === '/admin/pages') {
	require __DIR__ . '/page-editor.php';
	exit;
}
if (in_array($route, ['/admin/bookings', '/admin/services', '/admin/availability'], true)) {
	require __DIR__ . '/booking-admin.php';
	exit;
}
if ($route === '/book') {
	require __DIR__ . '/booking-page.php';
	exit;
}

if (in_array($route, ['/favicon.ico', '/brand/favicon', '/brand/logo'], true)) {
	$kind = $route === '/brand/logo' ? 'logo' : 'favicon';
	$name = branding_file($settings, $kind);
	if ($name === '') {
		if ($kind === 'favicon') {
			header('Location: /favicon.svg', true, 302);
		} else {
			http_response_code(404);
		}
		exit;
	}
	$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon'];
	header('Content-Type: ' . $types[pathinfo($name, PATHINFO_EXTENSION)]);
	header('Cache-Control: public, max-age=86400');
	header('Content-Security-Policy: default-src \'none\'; sandbox');
	readfile(branding_dir() . '/' . $name);
	exit;
}

if ($route === '/robots.txt') {
	header('Content-Type: text/plain; charset=utf-8');
	if ($settings['indexing_enabled'] !== '1') {
		echo "User-agent: *\nDisallow: /\n";
	} else {
		echo "User-agent: *\nAllow: /\nDisallow: /admin\nSitemap: " . site_origin($settings) . "/sitemap.xml\n";
	}
	exit;
}

if ($route === '/sitemap.xml') {
	header('Content-Type: application/xml; charset=utf-8');
	$location = h(site_origin($settings) . '/');
	$lastmod = gmdate('Y-m-d');
	echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
	echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\"><url><loc>{$location}</loc><lastmod>{$lastmod}</lastmod></url></urlset>";
	exit;
}

header('Content-Type: text/html; charset=utf-8');
$html = file_get_contents(__DIR__ . '/preview.html');
$title = h($settings['title']);
$description = h($settings['description']);
$html = preg_replace_callback('/<title>.*?<\/title>/is', fn() => '<title>' . $title . '</title>', $html, 1);
$html = preg_replace_callback('/<meta name="description" content="[^"]*">/i', fn() => '<meta name="description" content="' . $description . '">', $html, 1);

$socialTitle = h($settings['social_title']);
$socialDescription = h($settings['social_description']);
$socialImage = trim($settings['social_image']);
$robots = $settings['indexing_enabled'] === '1' ? 'index, follow' : 'noindex, nofollow';
$meta = '<meta name="robots" content="' . $robots . '">' . "\n";
$meta .= '<meta property="og:type" content="website">' . "\n";
$meta .= '<meta property="og:title" content="' . $socialTitle . '">' . "\n";
$meta .= '<meta property="og:description" content="' . $socialDescription . '">' . "\n";
$meta .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
$meta .= '<meta name="twitter:title" content="' . $socialTitle . '">' . "\n";
$meta .= '<meta name="twitter:description" content="' . $socialDescription . '">' . "\n";
if ($socialImage !== '' && filter_var($socialImage, FILTER_VALIDATE_URL)) {
	$meta .= '<meta property="og:image" content="' . h($socialImage) . '">' . "\n";
	$meta .= '<meta name="twitter:image" content="' . h($socialImage) . '">' . "\n";
}
if ($settings['canonical_url'] !== '') {
	$meta .= '<link rel="canonical" href="' . h($settings['canonical_url']) . '">' . "\n";
}
$html = str_replace('<meta name="template-seo" content="dynamic">', $meta, $html);
$html = str_replace('[[brand:favicon]]', branding_head($settings), $html);
$html = str_replace('[[brand:mark]]', branding_mark($settings, 'mark'), $html);

$content = $db->query('SELECT content_key, content_value FROM page_content')->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($content as $key => $value) {
	$html = str_replace('[[page:' . $key . ']]', h((string) $value), $html);
}
$services = $db->query('SELECT id, name, description, duration_minutes, buffer_minutes FROM services WHERE active = 1 ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC);
$serviceRows = '';
foreach ($services as $index => $service) {
	$number = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
	$details = trim((string) $service['description']);
	$details .= ($details !== '' ? ' · ' : '') . (int) $service['duration_minutes'] . ' minutes';
	if ((int) $service['buffer_minutes'] > 0) {
		$details .= ' · ' . (int) $service['buffer_minutes'] . ' minute buffer';
	}
	$serviceRows .= '<a class="service-row" href="/book?service_id=' . (int) $service['id'] . '"><span class="number">' . $number . '</span><span class="service-copy"><strong>' . h((string) $service['name']) . '</strong><small>' . h($details) . '</small></span><span class="plus" aria-hidden="true">↗</span></a>';
}
if ($serviceRows === '') {
	$serviceRows = '<p class="service-empty">Online sessions are currently unavailable. Please contact us directly.</p>';
}
$html = str_replace('<!--DYNAMIC_BOOKING_SERVICES-->', $serviceRows, $html);
echo $html;

function site_origin(array $settings): string
{
	if ($settings['canonical_url'] !== '') {
		return rtrim($settings['canonical_url'], '/');
	}
	$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
	$host = preg_replace('/[^a-zA-Z0-9.:-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
	return $scheme . '://' . $host;
}