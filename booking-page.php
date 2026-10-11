<?php
declare(strict_types=1);

admin_session_start();
$settings = setting_values(app_db());
header('Content-Type: text/html; charset=utf-8');
if (($settings['booking_enabled'] ?? '0') !== '1') {
	http_response_code(503);
	echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Booking unavailable</title><p>Online booking is temporarily unavailable. Please contact the studio.</p><a href="/">Return to the website</a></html>';
	exit;
}
$csrf = h(csrf_token());
$timezone = h(booking_timezone($settings)->getName());
$html = file_get_contents(__DIR__ . '/booking.html');
$html = str_replace('{{BOOKING_CSRF}}', $csrf, $html);
$html = str_replace('{{BOOKING_TIMEZONE}}', $timezone, $html);
$html = str_replace('{{BRAND_FAVICON}}', branding_head($settings), $html);
$html = str_replace('{{BRAND_MARK}}', branding_mark($settings, 'brand-mark'), $html);
echo $html;