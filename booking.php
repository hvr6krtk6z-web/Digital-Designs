<?php
declare(strict_types=1);

final class BookingError extends RuntimeException
{
	public function __construct(string $message, public readonly int $httpStatus = 400)
	{
		parent::__construct($message);
	}
}

function booking_json(array $payload, int $status = 200): never
{
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}

function booking_require_admin(): void
{
	admin_session_start();
	if (empty($_SESSION['admin_id'])) {
		booking_json(['error' => 'Sign in required.'], 401);
	}
}

function booking_api_csrf(): void
{
	admin_session_start();
	$headers = function_exists('getallheaders') ? getallheaders() : [];
	$provided = (string) ($headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
	$expected = (string) ($_SESSION['csrf_token'] ?? '');
	if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
		booking_json(['error' => 'Your session expired. Reload the booking page and try again.'], 403);
	}
}

function booking_timezone(array $settings): DateTimeZone
{
	try {
		return new DateTimeZone($settings['booking_timezone'] ?? 'Europe/London');
	} catch (Throwable) {
		return new DateTimeZone('UTC');
	}
}

function booking_service(PDO $db, int $serviceId, bool $activeOnly = true): array
{
	$query = $db->prepare('SELECT id, name, description, duration_minutes, buffer_minutes, price_cents, active FROM services WHERE id = :id' . ($activeOnly ? ' AND active = 1' : ''));
	$query->execute(['id' => $serviceId]);
	$service = $query->fetch(PDO::FETCH_ASSOC);
	if (!$service) {
		throw new BookingError('That service is not available.', 404);
	}
	return $service;
}

function booking_parse_local(string $date, string $time, DateTimeZone $timezone): ?DateTimeImmutable
{
	$value = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $timezone);
	$errors = DateTimeImmutable::getLastErrors();
	if (!$value || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $value->format('Y-m-d H:i') !== $date . ' ' . $time) {
		return null;
	}
	return $value;
}

function booking_valid_date(string $date): bool
{
	if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
		return false;
	}
	$value = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
	$errors = DateTimeImmutable::getLastErrors();
	return $value !== false && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])) && $value->format('Y-m-d') === $date;
}

function booking_date_hours(PDO $db, string $date): ?array
{
	$override = $db->prepare('SELECT is_closed, start_time, end_time FROM booking_dates WHERE local_date = :date');
	$override->execute(['date' => $date]);
	$special = $override->fetch(PDO::FETCH_ASSOC);
	if ($special) {
		return (int) $special['is_closed'] === 1
			? null
			: ['start_time' => $special['start_time'], 'end_time' => $special['end_time']];
	}

	$weekday = (int) (new DateTimeImmutable($date, new DateTimeZone('UTC')))->format('N');
	$query = $db->prepare('SELECT is_open, start_time, end_time FROM weekly_hours WHERE weekday = :weekday');
	$query->execute(['weekday' => $weekday]);
	$hours = $query->fetch(PDO::FETCH_ASSOC);
	if (!$hours || (int) $hours['is_open'] !== 1) {
		return null;
	}
	return ['start_time' => $hours['start_time'], 'end_time' => $hours['end_time']];
}

function booking_date_in_window(string $date, array $settings): bool
{
	$timezone = booking_timezone($settings);
	$now = new DateTimeImmutable('now', $timezone);
	$earliest = $now->modify('+' . max(0, min(8760, (int) ($settings['booking_notice_hours'] ?? 4))) . ' hours')->format('Y-m-d');
	$latest = $now->modify('+' . max(1, min(365, (int) ($settings['booking_max_days'] ?? 90))) . ' days')->format('Y-m-d');
	return $date >= $earliest && $date <= $latest;
}

function booking_slots_for_date(PDO $db, array $service, string $date, array $settings, ?int $excludeBookingId = null, bool $ignoreBookingDisabled = false): array
{
	if ((!$ignoreBookingDisabled && ($settings['booking_enabled'] ?? '0') !== '1') || !booking_valid_date($date) || !booking_date_in_window($date, $settings)) {
		return [];
	}
	$hours = booking_date_hours($db, $date);
	if (!$hours) {
		return [];
	}

	$timezone = booking_timezone($settings);
	$open = booking_parse_local($date, (string) $hours['start_time'], $timezone);
	$close = booking_parse_local($date, (string) $hours['end_time'], $timezone);
	if (!$open || !$close || $close <= $open) {
		return [];
	}
	$utc = new DateTimeZone('UTC');
	$openUtc = $open->setTimezone($utc);
	$closeUtc = $close->setTimezone($utc);
	$duration = (int) $service['duration_minutes'];
	$buffer = (int) $service['buffer_minutes'];
	$slotStep = max(5, min(120, (int) ($settings['booking_slot_minutes'] ?? 30)));
	$now = new DateTimeImmutable('now', $utc)->modify('+' . max(0, (int) ($settings['booking_notice_hours'] ?? 4)) . ' hours');
	$busyQuery = $db->prepare('SELECT id, starts_at_utc, blocked_until_utc FROM bookings WHERE local_date = :date AND status = "confirmed"' . ($excludeBookingId !== null ? ' AND id <> :exclude_id' : ''));
	$params = ['date' => $date];
	if ($excludeBookingId !== null) {
		$params['exclude_id'] = $excludeBookingId;
	}
	$busyQuery->execute($params);
	$busy = $busyQuery->fetchAll(PDO::FETCH_ASSOC);
	$slots = [];
	$step = new DateInterval('PT' . $slotStep . 'M');
	$blockMinutes = $duration + $buffer;

	for ($start = $openUtc; $start < $closeUtc; $start = $start->add($step)) {
		$serviceEnd = $start->modify('+' . $duration . ' minutes');
		$blockedUntil = $serviceEnd->modify('+' . $buffer . ' minutes');
		if ($start < $now || $blockedUntil > $closeUtc) {
			continue;
		}
		$overlap = false;
		$startKey = $start->format('Y-m-d\TH:i:s\Z');
		$endKey = $blockedUntil->format('Y-m-d\TH:i:s\Z');
		foreach ($busy as $booking) {
			if ($booking['starts_at_utc'] < $endKey && $booking['blocked_until_utc'] > $startKey) {
				$overlap = true;
				break;
			}
		}
		if ($overlap) {
			continue;
		}
		$localStart = $start->setTimezone($timezone);
		$localEnd = $serviceEnd->setTimezone($timezone);
		if ($localStart->format('Y-m-d') !== $date) {
			continue;
		}
		$slots[] = [
			'starts_at' => $startKey,
			'ends_at' => $serviceEnd->format('Y-m-d\TH:i:s\Z'),
			'blocked_until' => $endKey,
			'label' => $localStart->format('g:i A T') . ' – ' . $localEnd->format('g:i A T'),
		];
	}
	return $slots;
}

function booking_csrf_header_valid(): bool
{
	$headers = function_exists('getallheaders') ? getallheaders() : [];
	$provided = (string) ($headers['X-CSRF-Token'] ?? $headers['x-csrf-token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
	return isset($_SESSION['csrf_token']) && $provided !== '' && hash_equals((string) $_SESSION['csrf_token'], $provided);
}

function booking_create_public(PDO $db, array $payload, array $settings): array
{
	if (($settings['booking_enabled'] ?? '0') !== '1') {
		throw new BookingError('Online booking is temporarily unavailable.', 503);
	}
	if (!booking_csrf_header_valid()) {
		throw new BookingError('Your session expired. Reload the booking page and try again.', 403);
	}
	if (trim((string) ($payload['website'] ?? '')) !== '') {
		return ['message' => 'Your request has been received.', 'reference' => ''];
	}

	$name = trim((string) ($payload['name'] ?? ''));
	$email = trim((string) ($payload['email'] ?? ''));
	$phone = trim((string) ($payload['phone'] ?? ''));
	$note = trim((string) ($payload['note'] ?? ''));
	$date = (string) ($payload['date'] ?? '');
	$serviceId = filter_var($payload['service_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
	$startKey = (string) ($payload['starts_at'] ?? '');
	if (strlen($name) < 2 || strlen($name) > 100) {
		throw new BookingError('Enter a name between 2 and 100 characters.');
	}
	if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
		throw new BookingError('Enter a valid email address.');
	}
	if (strlen($phone) > 40 || strlen($note) > 1000) {
		throw new BookingError('Phone or note is too long.');
	}
	if (!$serviceId || !booking_valid_date($date) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $startKey)) {
		throw new BookingError('Choose an available service, date and time.');
	}

	$now = time();
	$ipHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . __DIR__ . 'booking');
	$db->exec('BEGIN IMMEDIATE');
	try {
		$limit = $db->prepare('SELECT request_count, window_started_at FROM booking_rate_limits WHERE ip_hash = :ip');
		$limit->execute(['ip' => $ipHash]);
		$rate = $limit->fetch(PDO::FETCH_ASSOC);
		if ($rate && $now - (int) $rate['window_started_at'] < 3600 && (int) $rate['request_count'] >= 5) {
			throw new BookingError('Too many booking requests. Please try again later.', 429);
		}
		$count = $rate && $now - (int) $rate['window_started_at'] < 3600 ? (int) $rate['request_count'] + 1 : 1;
		$saveLimit = $db->prepare('INSERT INTO booking_rate_limits (ip_hash, request_count, window_started_at) VALUES (:ip, :count, :started) ON CONFLICT(ip_hash) DO UPDATE SET request_count = excluded.request_count, window_started_at = excluded.window_started_at');
		$saveLimit->execute(['ip' => $ipHash, 'count' => $count, 'started' => $rate && $now - (int) $rate['window_started_at'] < 3600 ? (int) $rate['window_started_at'] : $now]);

		$service = booking_service($db, (int) $serviceId);
		$available = booking_slots_for_date($db, $service, $date, $settings);
		$slot = null;
		foreach ($available as $candidate) {
			if (hash_equals($candidate['starts_at'], $startKey)) {
				$slot = $candidate;
				break;
			}
		}
		if (!$slot) {
			throw new BookingError('That time was just taken or is no longer available. Choose another time.', 409);
		}

		$reference = 'FB-' . strtoupper(bin2hex(random_bytes(4)));
		$created = gmdate('Y-m-d\TH:i:s\Z');
		$insert = $db->prepare('INSERT INTO bookings (reference, service_id, service_name_snapshot, price_cents_snapshot, customer_name, customer_email, customer_phone, customer_note, local_date, starts_at_utc, ends_at_utc, blocked_until_utc, timezone, status, created_at, updated_at) VALUES (:reference, :service_id, :service_name, :price, :name, :email, :phone, :note, :local_date, :starts, :ends, :blocked_until, :timezone, "confirmed", :created, :updated)');
		$timezone = booking_timezone($settings)->getName();
		$insert->execute([
			'reference' => $reference,
			'service_id' => $service['id'],
			'service_name' => $service['name'],
			'price' => $service['price_cents'],
			'name' => $name,
			'email' => $email,
			'phone' => $phone,
			'note' => $note,
			'local_date' => $date,
			'starts' => $slot['starts_at'],
			'ends' => $slot['ends_at'],
			'blocked_until' => $slot['blocked_until'],
			'timezone' => $timezone,
			'created' => $created,
			'updated' => $created,
		]);
		$bookingId = (int) $db->lastInsertId();
		$event = $db->prepare('INSERT INTO booking_events (booking_id, actor, action, details, created_at) VALUES (:booking_id, "guest", "created", :details, :created)');
		$event->execute(['booking_id' => $bookingId, 'details' => $reference, 'created' => $created]);
		$db->exec('COMMIT');
		gcal_sync_booking($db, $bookingId);
		return ['reference' => $reference, 'message' => 'Your appointment is confirmed.', 'date' => $date, 'time' => $slot['label'], 'timezone' => $timezone, 'service' => $service['name'], 'starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at']];
	} catch (Throwable $error) {
		$db->exec('ROLLBACK');
		if ($error instanceof BookingError) {
			throw $error;
		}
		throw new BookingError('We could not save that booking. Please try again.', 500);
	}
}

function booking_api_dispatch(PDO $db, string $route): never
{
	$settings = setting_values($db);
	$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
	try {
		if ($route === '/api/services' && $method === 'GET') {
			$services = $db->query('SELECT id, name, description, duration_minutes, price_cents FROM services WHERE active = 1 ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC);
			booking_json(['services' => $services, 'timezone' => booking_timezone($settings)->getName(), 'enabled' => $settings['booking_enabled'] === '1']);
		}
		if ($route === '/api/calendar' && $method === 'GET') {
			$service = booking_service($db, (int) ($_GET['service_id'] ?? 0));
			$month = (string) ($_GET['month'] ?? '');
			if (!preg_match('/^\d{4}-\d{2}$/', $month) || !booking_valid_date($month . '-01')) {
				throw new BookingError('Choose a valid calendar month.');
			}
			$first = new DateTimeImmutable($month . '-01', new DateTimeZone('UTC'));
			$days = [];
			for ($day = 1; $day <= (int) $first->format('t'); $day++) {
				$date = $month . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
				$slots = booking_slots_for_date($db, $service, $date, $settings);
				$days[] = ['date' => $date, 'available' => count($slots) > 0, 'slots' => count($slots)];
			}
			$timezone = booking_timezone($settings);
			$now = new DateTimeImmutable('now', $timezone);
			booking_json(['month' => $month, 'week_starts_on' => 1, 'timezone' => $timezone->getName(), 'days' => $days, 'min_date' => $now->modify('+' . max(0, (int) $settings['booking_notice_hours']) . ' hours')->format('Y-m-d'), 'max_date' => $now->modify('+' . max(1, (int) $settings['booking_max_days']) . ' days')->format('Y-m-d')]);
		}
		if ($route === '/api/slots' && $method === 'GET') {
			$service = booking_service($db, (int) ($_GET['service_id'] ?? 0));
			$date = (string) ($_GET['date'] ?? '');
			if (!booking_valid_date($date)) {
				throw new BookingError('Choose a valid date.');
			}
			booking_json(['date' => $date, 'timezone' => booking_timezone($settings)->getName(), 'slots' => booking_slots_for_date($db, $service, $date, $settings)]);
		}
		if ($route === '/api/bookings' && $method === 'POST') {
			admin_session_start();
			$payload = json_decode((string) file_get_contents('php://input'), true);
			if (!is_array($payload)) {
				throw new BookingError('Invalid booking request.');
			}
			booking_json(booking_create_public($db, $payload, $settings), 201);
		}
		if ($route === '/api/admin/slots' && $method === 'GET') {
			booking_require_admin();
			$service = booking_service($db, (int) ($_GET['service_id'] ?? 0), false);
			$date = (string) ($_GET['date'] ?? '');
			$exclude = filter_var($_GET['exclude_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
			if (!booking_valid_date($date)) throw new BookingError('Choose a valid date.');
			booking_json(['slots' => booking_slots_for_date($db, $service, $date, $settings, $exclude ?: null, true)]);
		}
		if ($route === '/api/admin/bookings' && $method === 'GET') {
			booking_require_admin();
			$from = (string) ($_GET['from'] ?? (new DateTimeImmutable('today'))->format('Y-m-d'));
			$to = (string) ($_GET['to'] ?? (new DateTimeImmutable('+90 days'))->format('Y-m-d'));
			if (!booking_valid_date($from) || !booking_valid_date($to) || $to < $from || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 366) {
				throw new BookingError('Choose a valid date range.');
			}
			$query = $db->prepare('SELECT b.id, b.reference, b.service_id, b.service_name_snapshot, b.price_cents_snapshot, b.customer_name, b.customer_email, b.customer_phone, b.customer_note, b.local_date, b.starts_at_utc, b.ends_at_utc, b.timezone, b.status, b.created_at, s.duration_minutes, s.buffer_minutes FROM bookings b LEFT JOIN services s ON s.id = b.service_id WHERE b.local_date BETWEEN :from AND :to ORDER BY b.starts_at_utc LIMIT 500');
			$query->execute(['from' => $from, 'to' => $to]);
			booking_json(['bookings' => $query->fetchAll(PDO::FETCH_ASSOC)]);
		}
		if ($route === '/api/admin/bookings' && $method === 'POST') {
			booking_require_admin();
			booking_api_csrf();
			$payload = json_decode((string) file_get_contents('php://input'), true);
			if (!is_array($payload)) {
				throw new BookingError('Invalid update request.');
			}
			booking_admin_update($db, $payload, $settings);
			booking_json(['message' => 'Appointment updated.']);
		}
		booking_json(['error' => 'Not found.'], 404);
	} catch (BookingError $error) {
		booking_json(['error' => $error->getMessage()], $error->httpStatus);
	} catch (Throwable) {
		booking_json(['error' => 'The request could not be completed.'], 500);
	}
}

function booking_admin_update(PDO $db, array $payload, array $settings): void
{
	$id = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
	$action = (string) ($payload['action'] ?? '');
	if (!$id || !in_array($action, ['status', 'reschedule'], true)) {
		throw new BookingError('Choose a valid appointment and action.');
	}
	$db->exec('BEGIN IMMEDIATE');
	try {
		$query = $db->prepare('SELECT * FROM bookings WHERE id = :id');
		$query->execute(['id' => $id]);
		$booking = $query->fetch(PDO::FETCH_ASSOC);
		if (!$booking) {
			throw new BookingError('Appointment not found.', 404);
		}
		$updatedAt = gmdate('Y-m-d\TH:i:s\Z');
		if ($action === 'status') {
			$status = (string) ($payload['status'] ?? '');
			$allowed = ['confirmed', 'cancelled', 'completed', 'no_show'];
			if (!in_array($status, $allowed, true)) {
				throw new BookingError('Choose a valid appointment status.');
			}
			if (in_array($booking['status'], ['completed', 'no_show'], true) && $booking['status'] !== $status) {
				throw new BookingError('Completed appointments cannot be reopened.');
			}
			if ($status === 'confirmed' && $booking['status'] === 'cancelled') {
				$service = booking_service($db, (int) $booking['service_id'], false);
				$slots = booking_slots_for_date($db, $service, $booking['local_date'], $settings, (int) $id, true);
				if (!array_filter($slots, fn(array $slot): bool => $slot['starts_at'] === $booking['starts_at_utc'])) {
					throw new BookingError('That time is no longer available. Choose a new time before confirming.');
				}
			}
			$update = $db->prepare('UPDATE bookings SET status = :status, updated_at = :updated WHERE id = :id');
			$update->execute(['status' => $status, 'updated' => $updatedAt, 'id' => $id]);
			$eventAction = 'status:' . $status;
			$details = 'previous:' . $booking['status'];
		} else {
			if ($booking['status'] !== 'confirmed') {
				throw new BookingError('Only confirmed appointments can be rescheduled.');
			}
			$date = (string) ($payload['date'] ?? '');
			$startKey = (string) ($payload['starts_at'] ?? '');
			if (!booking_valid_date($date)) {
				throw new BookingError('Choose a valid date.');
			}
			$service = booking_service($db, (int) $booking['service_id'], false);
			$slots = booking_slots_for_date($db, $service, $date, $settings, (int) $id, true);
			$slot = null;
			foreach ($slots as $candidate) {
				if ($candidate['starts_at'] === $startKey) {
					$slot = $candidate;
					break;
				}
			}
			if (!$slot) {
				throw new BookingError('That time is not available. Choose another time.');
			}
			$update = $db->prepare('UPDATE bookings SET local_date = :date, starts_at_utc = :starts, ends_at_utc = :ends, blocked_until_utc = :blocked, timezone = :timezone, updated_at = :updated WHERE id = :id');
			$update->execute(['date' => $date, 'starts' => $slot['starts_at'], 'ends' => $slot['ends_at'], 'blocked' => $slot['blocked_until'], 'timezone' => booking_timezone($settings)->getName(), 'updated' => $updatedAt, 'id' => $id]);
			$eventAction = 'rescheduled';
			$details = $booking['local_date'] . ' ' . $booking['starts_at_utc'] . ' -> ' . $date . ' ' . $slot['starts_at'];
		}
		$event = $db->prepare('INSERT INTO booking_events (booking_id, actor, action, details, created_at) VALUES (:booking_id, :actor, :action, :details, :created)');
		$event->execute(['booking_id' => $id, 'actor' => (string) $_SESSION['admin_username'], 'action' => $eventAction, 'details' => $details, 'created' => $updatedAt]);
		$db->exec('COMMIT');
	} catch (Throwable $error) {
		$db->exec('ROLLBACK');
		if ($error instanceof BookingError) {
			throw $error;
		}
		throw new BookingError('The appointment could not be updated.', 500);
	}
	gcal_sync_booking($db, (int) $id);
}