<?php
declare(strict_types=1);

function gcal_api_base(): string
{
	return rtrim(getenv('GOOGLE_CALENDAR_API_BASE') ?: 'https://www.googleapis.com/calendar/v3', '/');
}

function gcal_token_url(): string
{
	return getenv('GOOGLE_TOKEN_URL') ?: 'https://oauth2.googleapis.com/token';
}

function gcal_configured(array $settings): bool
{
	return ($settings['google_sync_enabled'] ?? '0') === '1'
		&& ($settings['google_calendar_id'] ?? '') !== ''
		&& ($settings['google_sa_email'] ?? '') !== ''
		&& ($settings['google_sa_key'] ?? '') !== '';
}

function gcal_set(PDO $db, string $name, string $value): void
{
	$db->prepare('INSERT INTO settings (name, value) VALUES (:name, :value) ON CONFLICT(name) DO UPDATE SET value = excluded.value')->execute(['name' => $name, 'value' => $value]);
}

function gcal_disconnect(PDO $db): void
{
	foreach (['google_calendar_id', 'google_sa_email', 'google_sa_key', 'google_token', 'google_token_expires'] as $name) {
		gcal_set($db, $name, '');
	}
	gcal_set($db, 'google_sync_enabled', '0');
}

// Returns [status, decoded body]. Status 0 means the request could not be made.
function gcal_http(string $method, string $url, array $headers, ?string $body): array
{
	$context = stream_context_create(['http' => [
		'method' => $method,
		'header' => implode("\r\n", $headers),
		'content' => $body ?? '',
		'timeout' => 6,
		'ignore_errors' => true,
	]]);
	$response = @file_get_contents($url, false, $context);
	if ($response === false || empty($http_response_header[0])) {
		return [0, null];
	}
	preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
	return [(int) ($match[1] ?? 0), json_decode($response, true)];
}

function gcal_b64(string $value): string
{
	return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function gcal_token(PDO $db, array $settings): string
{
	if (($settings['google_token'] ?? '') !== '' && (int) ($settings['google_token_expires'] ?? 0) > time() + 60) {
		return $settings['google_token'];
	}
	$now = time();
	$claims = ['iss' => $settings['google_sa_email'], 'scope' => 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.readonly', 'aud' => gcal_token_url(), 'iat' => $now, 'exp' => $now + 3300];
	$unsigned = gcal_b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . gcal_b64(json_encode($claims));
	$key = openssl_pkey_get_private($settings['google_sa_key']);
	if (!$key || !openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
		throw new RuntimeException('The saved Google private key is not valid.');
	}
	[$status, $data] = gcal_http('POST', gcal_token_url(), ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
		'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
		'assertion' => $unsigned . '.' . gcal_b64($signature),
	]));
	if ($status !== 200 || empty($data['access_token'])) {
		throw new RuntimeException($status === 0 ? 'Could not reach Google.' : 'Google rejected the service account credentials.');
	}
	gcal_set($db, 'google_token', (string) $data['access_token']);
	gcal_set($db, 'google_token_expires', (string) ($now + (int) ($data['expires_in'] ?? 3000)));
	return (string) $data['access_token'];
}

function gcal_call(PDO $db, array $settings, string $method, string $path, ?array $payload = null): array
{
	$token = gcal_token($db, $settings);
	$url = gcal_api_base() . '/calendars/' . rawurlencode($settings['google_calendar_id']) . $path;
	$headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
	[$status, $data] = gcal_http($method, $url, $headers, $payload === null ? null : json_encode($payload));
	if ($status === 401) {
		gcal_set($db, 'google_token', '');
		gcal_set($db, 'google_token_expires', '0');
		$settings['google_token'] = '';
		$token = gcal_token($db, $settings);
		$headers[0] = 'Authorization: Bearer ' . $token;
		[$status, $data] = gcal_http($method, $url, $headers, $payload === null ? null : json_encode($payload));
	}
	return [$status, $data];
}

function gcal_event_id(string $reference): string
{
	return 'fb' . strtolower(bin2hex($reference));
}

function gcal_event_body(array $booking): array
{
	$lines = ['Reference: ' . $booking['reference'], 'Guest: ' . $booking['customer_name'], 'Email: ' . $booking['customer_email']];
	if ($booking['customer_phone'] !== '') {
		$lines[] = 'Phone: ' . $booking['customer_phone'];
	}
	if ($booking['customer_note'] !== '') {
		$lines[] = 'Note: ' . $booking['customer_note'];
	}
	$status = $booking['status'] === 'no_show' ? 'No-show: ' : '';
	return [
		'summary' => $status . $booking['service_name_snapshot'] . ' – ' . $booking['customer_name'],
		'description' => implode("\n", $lines),
		'start' => ['dateTime' => $booking['starts_at_utc'], 'timeZone' => $booking['timezone']],
		'end' => ['dateTime' => $booking['ends_at_utc'], 'timeZone' => $booking['timezone']],
		'status' => 'confirmed',
		'reminders' => ['useDefault' => true],
	];
}

// Best-effort: records the outcome on the booking and never throws.
function gcal_sync_booking(PDO $db, int $bookingId): void
{
	try {
		$settings = setting_values($db);
		if (!gcal_configured($settings)) {
			return;
		}
		$query = $db->prepare('SELECT * FROM bookings WHERE id = :id');
		$query->execute(['id' => $bookingId]);
		$booking = $query->fetch(PDO::FETCH_ASSOC);
		if (!$booking) {
			return;
		}
		$eventId = gcal_event_id($booking['reference']);
		$error = '';
		$newEventId = $booking['google_event_id'];
		if ($booking['status'] === 'cancelled') {
			if ($booking['google_event_id'] !== '') {
				[$status] = gcal_call($db, $settings, 'DELETE', '/events/' . rawurlencode($eventId));
				if ($status === 204 || $status === 200 || $status === 404 || $status === 410) {
					$newEventId = '';
				} else {
					$error = 'Google returned an error removing the event (' . $status . ').';
				}
			}
		} else {
			$body = gcal_event_body($booking);
			[$status] = gcal_call($db, $settings, 'POST', '/events', $body + ['id' => $eventId]);
			if ($status === 409) {
				[$status] = gcal_call($db, $settings, 'PATCH', '/events/' . rawurlencode($eventId), $body);
			}
			if ($status === 200) {
				$newEventId = $eventId;
			} else {
				$error = $status === 404 ? 'Calendar not found. Check the calendar ID and that it is shared with the service account.' : ($status === 403 ? 'Google refused access. Share the calendar with the service account and allow it to make changes to events.' : 'Google returned an error saving the event (' . $status . ').');
			}
		}
		$db->prepare('UPDATE bookings SET google_event_id = :event, google_sync_error = :error, google_synced_at = :synced WHERE id = :id')
			->execute(['event' => $newEventId, 'error' => $error, 'synced' => $error === '' ? gmdate('Y-m-d\TH:i:s\Z') : '', 'id' => $bookingId]);
	} catch (Throwable $error) {
		try {
			$db->prepare('UPDATE bookings SET google_sync_error = :error WHERE id = :id')->execute(['error' => substr($error->getMessage(), 0, 200), 'id' => $bookingId]);
		} catch (Throwable) {
		}
	}
}

// Syncs upcoming bookings that are missing from Google, failed earlier, or were cancelled while still on the calendar.
function gcal_sync_pending(PDO $db): array
{
	$query = $db->prepare('SELECT id FROM bookings WHERE (status = "confirmed" AND starts_at_utc >= :now AND (google_event_id = "" OR google_sync_error <> "")) OR (status = "cancelled" AND google_event_id <> "") ORDER BY starts_at_utc LIMIT 50');
	$query->execute(['now' => gmdate('Y-m-d\TH:i:s\Z')]);
	$ids = $query->fetchAll(PDO::FETCH_COLUMN);
	$failed = 0;
	foreach ($ids as $id) {
		gcal_sync_booking($db, (int) $id);
		$check = $db->prepare('SELECT google_sync_error FROM bookings WHERE id = :id');
		$check->execute(['id' => $id]);
		if ($check->fetchColumn() !== '') {
			$failed++;
		}
	}
	return [count($ids), $failed];
}

function gcal_test(PDO $db, array $settings): string
{
	try {
		[$status, $data] = gcal_call($db, $settings, 'GET', '');
		if ($status === 200) {
			return 'Connected to “' . (string) ($data['summary'] ?? 'your calendar') . '”.';
		}
		if ($status === 404) {
			return 'Calendar not found. Check the calendar ID and that it is shared with the service account.';
		}
		return $status === 0 ? 'Could not reach Google.' : 'Google returned an error (' . $status . '). Share the calendar with the service account and allow it to make changes to events.';
	} catch (Throwable $error) {
		return $error->getMessage();
	}
}
