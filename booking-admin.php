<?php
declare(strict_types=1);

admin_session_start();
$db = app_db();
$route = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/') ?: '/';
$adminCount = (int) $db->query('SELECT COUNT(*) FROM admins')->fetchColumn();
if ($adminCount === 0 || empty($_SESSION['admin_id'])) {
	header('Location: /admin', true, 303);
	exit;
}

$settings = setting_values($db);
$csrf = csrf_token();
$errors = [];
$notice = '';
$days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
$utc = new DateTimeZone('UTC');
$timezone = booking_timezone($settings);

function booking_admin_conflicts(PDO $db, array $weekly, array $overrides, DateTimeZone $timezone): array
{
	$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
	$query = $db->prepare('SELECT reference, local_date, starts_at_utc, blocked_until_utc FROM bookings WHERE status = "confirmed" AND starts_at_utc >= :now ORDER BY starts_at_utc');
	$query->execute(['now' => $now->format('Y-m-d\TH:i:s\Z')]);
	$conflicts = [];
	foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $booking) {
		$start = (new DateTimeImmutable($booking['starts_at_utc'], new DateTimeZone('UTC')))->setTimezone($timezone);
		$end = (new DateTimeImmutable($booking['blocked_until_utc'], new DateTimeZone('UTC')))->setTimezone($timezone);
		$date = $start->format('Y-m-d');
		if (isset($overrides[$date])) {
			$hours = $overrides[$date]['is_closed'] ? null : $overrides[$date];
		} else {
			$weekday = (int) $start->format('N');
			$hours = !empty($weekly[$weekday]['is_open']) ? $weekly[$weekday] : null;
		}
		if (!$hours) {
			$conflicts[] = $booking['reference'];
			continue;
		}
		$open = booking_parse_local($date, (string) $hours['start_time'], $timezone);
		$close = booking_parse_local($date, (string) $hours['end_time'], $timezone);
		if (!$open || !$close || $start < $open || $end > $close) {
			$conflicts[] = $booking['reference'];
		}
	}
	return $conflicts;
}

function booking_admin_redirect(string $route, string $notice): never
{
	$separator = str_contains($route, '?') ? '&' : '?';
	header('Location: ' . $route . $separator . 'saved=' . rawurlencode($notice), true, 303);
	exit;
}

if (isset($_GET['saved'])) {
	$notice = (string) $_GET['saved'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!valid_csrf()) {
		$errors[] = 'Your session expired. Refresh the page and try again.';
	} else {
		$action = (string) ($_POST['action'] ?? '');
		try {
			if ($route === '/admin/services' && in_array($action, ['save_service', 'toggle_service'], true)) {
				if ($action === 'toggle_service') {
					$id = filter_var($_POST['service_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
					if (!$id) throw new BookingError('Choose a valid service.');
					$db->prepare('UPDATE services SET active = CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id = :id')->execute(['id' => $id]);
					booking_admin_redirect($route, 'Service availability updated.');
				}
				$id = (int) ($_POST['service_id'] ?? 0);
				$name = trim((string) ($_POST['name'] ?? ''));
				$description = trim((string) ($_POST['description'] ?? ''));
				$duration = filter_var($_POST['duration_minutes'] ?? null, FILTER_VALIDATE_INT);
				$buffer = filter_var($_POST['buffer_minutes'] ?? null, FILTER_VALIDATE_INT);
				if (strlen($name) < 3 || strlen($name) > 80 || strlen($description) > 240) throw new BookingError('Use a service name of 3–80 characters and a description of at most 240 characters.');
				if ($duration === false || $duration < 15 || $duration > 480 || $duration % 5 !== 0) throw new BookingError('Duration must be in 5-minute increments between 15 minutes and 8 hours.');
				if ($buffer === false || $buffer < 0 || $buffer > 120 || $buffer % 5 !== 0) throw new BookingError('Buffer must be in 5-minute increments from 0 to 120 minutes.');
				$duplicate = $db->prepare('SELECT id FROM services WHERE name = :name COLLATE NOCASE AND id <> :id');
				$duplicate->execute(['name' => $name, 'id' => $id]);
				if ($duplicate->fetch()) throw new BookingError('A service with that name already exists.');
				if ($id > 0) {
					$save = $db->prepare('UPDATE services SET name = :name, description = :description, duration_minutes = :duration, buffer_minutes = :buffer WHERE id = :id');
					$save->execute(['name' => $name, 'description' => $description, 'duration' => $duration, 'buffer' => $buffer, 'id' => $id]);
				} else {
					$count = (int) $db->query('SELECT COUNT(*) FROM services')->fetchColumn();
					if ($count >= 30) throw new BookingError('The service limit is 30.');
					$save = $db->prepare('INSERT INTO services (name, description, duration_minutes, buffer_minutes, active, sort_order, created_at) VALUES (:name, :description, :duration, :buffer, 1, :sort, :created)');
					$save->execute(['name' => $name, 'description' => $description, 'duration' => $duration, 'buffer' => $buffer, 'sort' => $count, 'created' => gmdate('Y-m-d\TH:i:s\Z')]);
				}
				booking_admin_redirect($route, 'Service saved.');
			}

			if ($route === '/admin/availability' && in_array($action, ['save_availability', 'save_exception', 'delete_exception'], true)) {
				if ($action === 'save_availability') {
					$timezoneName = trim((string) ($_POST['booking_timezone'] ?? ''));
					try { $newTimezone = new DateTimeZone($timezoneName); } catch (Throwable) { throw new BookingError('Choose a valid timezone identifier.'); }
					if (!in_array($timezoneName, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true) && $timezoneName !== 'UTC') throw new BookingError('Choose a supported IANA timezone.');
					$slotStep = filter_var($_POST['booking_slot_minutes'] ?? null, FILTER_VALIDATE_INT);
					$noticeHours = filter_var($_POST['booking_notice_hours'] ?? null, FILTER_VALIDATE_INT);
					$maxDays = filter_var($_POST['booking_max_days'] ?? null, FILTER_VALIDATE_INT);
					if ($slotStep === false || $slotStep < 5 || $slotStep > 120 || $slotStep % 5 !== 0) throw new BookingError('Slot interval must be in 5-minute increments from 5 to 120.');
					if ($noticeHours === false || $noticeHours < 0 || $noticeHours > 8760) throw new BookingError('Minimum notice must be between 0 and 8,760 hours.');
					if ($maxDays === false || $maxDays < 1 || $maxDays > 365) throw new BookingError('Booking window must be between 1 and 365 days.');
					$weekly = [];
					foreach ($days as $weekday => $dayName) {
						$isOpen = isset($_POST['day_open'][$weekday]);
						$start = trim((string) ($_POST['start_time'][$weekday] ?? ''));
						$end = trim((string) ($_POST['end_time'][$weekday] ?? ''));
						if ($isOpen && (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end) || $start >= $end)) throw new BookingError($dayName . ' needs a valid opening and closing time.');
						$weekly[$weekday] = ['is_open' => $isOpen ? 1 : 0, 'start_time' => $isOpen ? $start : null, 'end_time' => $isOpen ? $end : null];
					}
					$overrides = [];
					foreach ($db->query('SELECT local_date, is_closed, start_time, end_time FROM booking_dates')->fetchAll(PDO::FETCH_ASSOC) as $row) $overrides[$row['local_date']] = $row;
					$conflicts = booking_admin_conflicts($db, $weekly, $overrides, $newTimezone);
					if ($conflicts) throw new BookingError('These changes conflict with ' . count($conflicts) . ' confirmed booking(s). Reschedule or cancel them first.');
					$db->beginTransaction();
					$saveSetting = $db->prepare('INSERT INTO settings (name, value) VALUES (:name, :value) ON CONFLICT(name) DO UPDATE SET value = excluded.value');
					foreach (['booking_timezone' => $timezoneName, 'booking_slot_minutes' => (string) $slotStep, 'booking_notice_hours' => (string) $noticeHours, 'booking_max_days' => (string) $maxDays, 'booking_enabled' => isset($_POST['booking_enabled']) ? '1' : '0'] as $key => $value) $saveSetting->execute(['name' => $key, 'value' => $value]);
					$saveHours = $db->prepare('INSERT INTO weekly_hours (weekday, is_open, start_time, end_time) VALUES (:weekday, :is_open, :start, :end) ON CONFLICT(weekday) DO UPDATE SET is_open = excluded.is_open, start_time = excluded.start_time, end_time = excluded.end_time');
					foreach ($weekly as $weekday => $hours) $saveHours->execute(['weekday' => $weekday, 'is_open' => $hours['is_open'], 'start' => $hours['start_time'], 'end' => $hours['end_time']]);
					$db->commit();
					booking_admin_redirect($route, 'Availability saved.');
				}

				$date = (string) ($_POST['local_date'] ?? '');
				if (!booking_valid_date($date)) throw new BookingError('Choose a valid exception date.');
				$overrides = [];
				foreach ($db->query('SELECT local_date, is_closed, start_time, end_time FROM booking_dates')->fetchAll(PDO::FETCH_ASSOC) as $row) $overrides[$row['local_date']] = $row;
				if ($action === 'delete_exception') {
					unset($overrides[$date]);
					$conflicts = booking_admin_conflicts($db, $db->query('SELECT weekday, is_open, start_time, end_time FROM weekly_hours')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC), $overrides, $timezone);
					if ($conflicts) throw new BookingError('Removing this exception conflicts with confirmed bookings. Reschedule or cancel them first.');
					$db->prepare('DELETE FROM booking_dates WHERE local_date = :date')->execute(['date' => $date]);
					booking_admin_redirect($route, 'Date exception removed.');
				}
				$isClosed = isset($_POST['is_closed']);
				$start = trim((string) ($_POST['start_time'] ?? ''));
				$end = trim((string) ($_POST['end_time'] ?? ''));
				$note = trim((string) ($_POST['note'] ?? ''));
				if (strlen($note) > 120) throw new BookingError('The exception note must be 120 characters or fewer.');
				if (!$isClosed && (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end) || $start >= $end)) throw new BookingError('Enter valid opening and closing times for the special hours.');
			$overrides[$date] = ['is_closed' => $isClosed ? 1 : 0, 'start_time' => $isClosed ? null : $start, 'end_time' => $isClosed ? null : $end];
			$weekly = $db->query('SELECT weekday, is_open, start_time, end_time FROM weekly_hours')->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
			$conflicts = booking_admin_conflicts($db, $weekly, $overrides, $timezone);
			if ($conflicts) throw new BookingError('That change conflicts with ' . count($conflicts) . ' confirmed booking(s). Reschedule or cancel them first.');
			$save = $db->prepare('INSERT INTO booking_dates (local_date, is_closed, start_time, end_time, note) VALUES (:date, :closed, :start, :end, :note) ON CONFLICT(local_date) DO UPDATE SET is_closed = excluded.is_closed, start_time = excluded.start_time, end_time = excluded.end_time, note = excluded.note');
			$save->execute(['date' => $date, 'closed' => $isClosed ? 1 : 0, 'start' => $isClosed ? null : $start, 'end' => $isClosed ? null : $end, 'note' => $note]);
			booking_admin_redirect($route, 'Date exception saved.');
			}

			if ($route === '/admin/bookings' && in_array($action, ['status', 'reschedule'], true)) {
				booking_admin_update($db, $_POST, $settings);
				$backDate = (string) ($_POST['return_date'] ?? '');
				booking_admin_redirect($route . ($backDate !== '' ? '?date=' . rawurlencode($backDate) : ''), 'Appointment updated.');
			}
			$errors[] = 'That action is not available here.';
		} catch (BookingError $error) {
			$errors[] = $error->getMessage();
		} catch (Throwable) {
			if ($db->inTransaction()) $db->rollBack();
			$errors[] = 'The change could not be saved. Please check the values and try again.';
		}
	}
}

$pageTitle = match ($route) {
	'/admin/bookings' => 'Appointments',
	'/admin/services' => 'Services',
	default => 'Availability',
};
$baseUrl = match ($route) {
	'/admin/bookings' => '/admin/bookings',
	'/admin/services' => '/admin/services',
	default => '/admin/availability',
};
$timezones = ['UTC', 'Europe/London', 'Europe/Dublin', 'Europe/Paris', 'Europe/Berlin', 'Europe/Amsterdam', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'America/Toronto', 'America/Vancouver', 'Australia/Sydney', 'Pacific/Auckland'];
$csrfJson = json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<meta name="booking-csrf" content="<?= h($csrf) ?>">
	<title><?= h($pageTitle) ?> | Fieldnote Booking</title>
	<link rel="stylesheet" href="/admin.css">
	<?= branding_head($settings) ?>
	<script src="/booking-admin.js" defer></script>
</head>
<body class="admin-page">
	<header class="admin-topbar">
		<a class="admin-brand" href="/" target="_blank" rel="noreferrer"><?= branding_mark($settings, 'admin-mark') ?><span>Fieldnote Booking <small>Booking admin</small></span></a>
		<div class="admin-account"><span><?= h((string) $_SESSION['admin_username']) ?></span><form method="post" action="/admin"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><button class="text-button" name="action" value="logout">Sign out</button></form></div>
	</header>
	<nav class="admin-nav" aria-label="Admin sections"><a href="/admin" >SEO manager</a><a href="/admin/bookings" <?= $route === '/admin/bookings' ? 'aria-current="page"' : '' ?>>Appointments</a><a href="/admin/availability" <?= $route === '/admin/availability' ? 'aria-current="page"' : '' ?>>Availability</a><a href="/admin/services" <?= $route === '/admin/services' ? 'aria-current="page"' : '' ?>>Services</a><a href="/admin/pages" <?= $route === '/admin/pages' ? 'aria-current="page"' : '' ?>>Page editor</a></nav>
	<main class="admin-main booking-admin-main">
		<div class="dashboard-heading"><div><p class="eyebrow">Fieldnote Booking</p><h1><?= h($pageTitle) ?></h1><p class="muted">Signed in as <?= h((string) $_SESSION['admin_username']) ?></p></div><?php if ($route === '/admin/bookings'): ?><a class="outline-button" href="/book" target="_blank" rel="noreferrer">Open booking page <span aria-hidden="true">↗</span></a><?php endif; ?></div>
		<?php if ($notice): ?><div class="success" role="status"><?= h($notice) ?></div><?php endif; ?>
		<?php if ($errors): ?><div class="alert" role="alert"><?php foreach ($errors as $error): ?><p><?= h($error) ?></p><?php endforeach; ?></div><?php endif; ?>
		<?php if ($route === '/admin/services'): ?>
			<div class="admin-section-heading"><div><h2>Booking services</h2><p>Durations and buffers determine which start times are offered.</p></div></div>
			<div class="service-admin-list">
				<?php foreach ($db->query('SELECT id, name, description, duration_minutes, buffer_minutes, active FROM services ORDER BY sort_order, name')->fetchAll(PDO::FETCH_ASSOC) as $service): ?>
					<form method="post" class="service-admin-form"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>"><input type="hidden" name="action" value="save_service"><div class="form-grid">
						<label class="field">Service name<input name="name" value="<?= h($service['name']) ?>" minlength="3" maxlength="80" required></label>
						<label class="field">Duration in minutes<input name="duration_minutes" type="number" value="<?= (int) $service['duration_minutes'] ?>" min="15" max="480" step="5" required></label>
						<label class="field full">Description<textarea name="description" rows="2" maxlength="240"><?= h($service['description']) ?></textarea></label>
						<label class="field">Buffer after appointment<input name="buffer_minutes" type="number" value="<?= (int) $service['buffer_minutes'] ?>" min="0" max="120" step="5" required></label>
						<div class="service-actions"><button class="primary-button" type="submit">Save service</button><span class="service-status <?= $service['active'] ? 'is-active' : '' ?>"><?= $service['active'] ? 'Active on site' : 'Paused' ?></span></div>
					</div></form>
					<form method="post" class="inline-action"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="toggle_service"><input type="hidden" name="service_id" value="<?= (int) $service['id'] ?>"><button class="text-action" type="submit"><?= $service['active'] ? 'Pause service' : 'Activate service' ?></button></form>
				<?php endforeach; ?>
			</div>
			<section class="admin-panel"><h2>Add a service</h2><p>New bookings use the service duration, buffer and name saved at booking time.</p><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_service"><input type="hidden" name="service_id" value="0"><label class="field">Service name<input name="name" minlength="3" maxlength="80" required></label><label class="field">Duration in minutes<input name="duration_minutes" type="number" value="60" min="15" max="480" step="5" required></label><label class="field full">Description<textarea name="description" rows="2" maxlength="240"></textarea></label><label class="field">Buffer after appointment<input name="buffer_minutes" type="number" value="15" min="0" max="120" step="5" required></label><div class="form-actions"><span class="save-note">Maximum 30 services.</span><button class="primary-button" type="submit">Add service</button></div></form></section>
		<?php elseif ($route === '/admin/availability'): ?>
			<form method="post" class="settings-form"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_availability"><section class="settings-section"><div class="section-title"><span class="section-icon">01</span><div><h2>Booking rules</h2><p>All calendars use this business timezone, including daylight-saving changes.</p></div></div><div class="form-grid">
				<label class="field">Business timezone<input name="booking_timezone" list="timezone-options" value="<?= h($settings['booking_timezone']) ?>" required><datalist id="timezone-options"><?php foreach ($timezones as $zone): ?><option value="<?= h($zone) ?>"><?php endforeach; ?></datalist></label>
				<label class="field">Time between offered starts<input type="number" name="booking_slot_minutes" value="<?= (int) $settings['booking_slot_minutes'] ?>" min="5" max="120" step="5" required><span class="field-hint">Minutes; use smaller steps for more start times</span></label>
				<label class="field">Minimum notice<input type="number" name="booking_notice_hours" value="<?= (int) $settings['booking_notice_hours'] ?>" min="0" max="8760" required><span class="field-hint">Hours before an appointment can be booked</span></label>
				<label class="field">Booking window<input type="number" name="booking_max_days" value="<?= (int) $settings['booking_max_days'] ?>" min="1" max="365" required><span class="field-hint">Days into the future</span></label>
			</div><label class="toggle-row booking-enable"><span><strong>Accept new online bookings</strong><small>Turning this off hides all public time slots. Existing appointments remain in the admin.</small></span><input type="checkbox" name="booking_enabled" value="1" <?= $settings['booking_enabled'] === '1' ? 'checked' : '' ?>><span class="switch" aria-hidden="true"></span></label></section>
			<section class="settings-section"><div class="section-title"><span class="section-icon">02</span><div><h2>Weekly hours</h2><p>Set local opening hours. Slots that cannot fit the service and buffer are omitted.</p></div></div><div class="weekly-hours">
				<?php $hoursQuery = $db->query('SELECT weekday, is_open, start_time, end_time FROM weekly_hours'); $hoursByDay = $hoursQuery->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC); ?>
				<?php foreach ($days as $weekday => $dayName): $hours = $hoursByDay[$weekday] ?? ['is_open' => 0, 'start_time' => '09:00', 'end_time' => '17:00']; ?>
					<div class="weekly-row"><label class="day-check"><input type="checkbox" name="day_open[<?= $weekday ?>]" value="1" <?= (int) $hours['is_open'] === 1 ? 'checked' : '' ?>><span><?= h($dayName) ?></span></label><label class="time-field"><span class="sr-only"><?= h($dayName) ?> opens</span><input type="time" name="start_time[<?= $weekday ?>]" value="<?= h((string) $hours['start_time']) ?>"></label><span class="time-separator">to</span><label class="time-field"><span class="sr-only"><?= h($dayName) ?> closes</span><input type="time" name="end_time[<?= $weekday ?>]" value="<?= h((string) $hours['end_time']) ?>"></label></div>
				<?php endforeach; ?>
			</div></section><div class="form-actions"><span class="save-note">Changes that conflict with confirmed appointments are rejected.</span><button class="primary-button" type="submit">Save availability</button></div></form>
			<section class="admin-panel"><div class="admin-section-heading"><div><h2>Date exceptions</h2><p>Close a date or replace its regular opening hours.</p></div></div><form method="post" class="form-grid exception-form"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="save_exception"><label class="field">Date<input type="date" name="local_date" min="<?= (new DateTimeImmutable('today', $timezone))->format('Y-m-d') ?>" required></label><label class="field">Reason <span class="optional">Optional</span><input name="note" maxlength="120"></label><label class="field">Open<input type="time" name="start_time" value="10:00"></label><label class="field">Close<input type="time" name="end_time" value="15:00"></label><label class="day-check exception-check"><input type="checkbox" name="is_closed" value="1" checked><span>Closed all day</span></label><div class="form-actions"><span class="save-note">Existing confirmed appointments must be moved or cancelled first.</span><button class="primary-button" type="submit">Save exception</button></div></form>
				<?php $exceptions = $db->query('SELECT local_date, is_closed, start_time, end_time, note FROM booking_dates WHERE local_date >= date("now") ORDER BY local_date LIMIT 100')->fetchAll(PDO::FETCH_ASSOC); ?>
				<?php if ($exceptions): ?><div class="exception-list"><?php foreach ($exceptions as $exception): ?><div class="exception-row"><span><strong><?= h($exception['local_date']) ?></strong><small><?= h($exception['note']) ?></small></span><span><?= (int) $exception['is_closed'] ? 'Closed' : h($exception['start_time'] . '–' . $exception['end_time']) ?></span><form method="post"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="delete_exception"><input type="hidden" name="local_date" value="<?= h($exception['local_date']) ?>"><button class="text-action" type="submit">Remove</button></form></div><?php endforeach; ?></div><?php else: ?><p class="empty-admin">No upcoming date exceptions.</p><?php endif; ?></section>
		<?php else: ?>
			<?php
			$monthInput = (string) ($_GET['month'] ?? (new DateTimeImmutable('now', $timezone))->format('Y-m'));
			if (!preg_match('/^\d{4}-\d{2}$/', $monthInput) || !booking_valid_date($monthInput . '-01')) $monthInput = (new DateTimeImmutable('now', $timezone))->format('Y-m');
			$monthStart = new DateTimeImmutable($monthInput . '-01', $timezone);
			$todayLocal = (new DateTimeImmutable('now', $timezone))->format('Y-m-d');
			$selected = (string) ($_GET['date'] ?? $todayLocal);
			if (!booking_valid_date($selected) || substr($selected, 0, 7) !== $monthInput) $selected = $monthStart->format('Y-m-d');
			$monthEnd = $monthStart->modify('last day of this month');
			$query = $db->prepare('SELECT local_date, status, COUNT(*) AS total FROM bookings WHERE local_date BETWEEN :from AND :to GROUP BY local_date, status');
			$query->execute(['from' => $monthStart->format('Y-m-d'), 'to' => $monthEnd->format('Y-m-d')]);
			$counts = [];
			foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) $counts[$row['local_date']][$row['status']] = (int) $row['total'];
			$bookingQuery = $db->prepare('SELECT b.*, s.duration_minutes FROM bookings b LEFT JOIN services s ON s.id = b.service_id WHERE b.local_date = :date ORDER BY b.starts_at_utc LIMIT 100');
			$bookingQuery->execute(['date' => $selected]);
			$dayBookings = $bookingQuery->fetchAll(PDO::FETCH_ASSOC);
			$offset = ((int) $monthStart->format('N')) - 1;
			$previousMonth = $monthStart->modify('-1 month')->format('Y-m');
			$nextMonth = $monthStart->modify('+1 month')->format('Y-m');
			?>
			<div class="calendar-admin-toolbar"><div><h2><?= h($monthStart->format('F Y')) ?></h2><p>Choose a day to review appointments.</p></div><div class="month-controls"><a class="icon-button" href="<?= h($baseUrl . '?month=' . $previousMonth) ?>" aria-label="Previous month">←</a><a class="icon-button" href="<?= h($baseUrl . '?month=' . $nextMonth) ?>" aria-label="Next month">→</a></div></div>
			<div class="admin-calendar" role="grid" aria-label="Appointments for <?= h($monthStart->format('F Y')) ?>"><div class="admin-calendar-weekdays" role="row"><?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $dayName): ?><span role="columnheader"><?= $dayName ?></span><?php endforeach; ?></div><div class="admin-calendar-days"><?php for ($blank = 0; $blank < $offset; $blank++): ?><span class="admin-calendar-blank" aria-hidden="true"></span><?php endfor; ?><?php for ($day = 1; $day <= (int) $monthStart->format('t'); $day++): $date = $monthInput . '-' . str_pad((string) $day, 2, '0', STR_PAD_LEFT); $total = array_sum($counts[$date] ?? []); $confirmed = $counts[$date]['confirmed'] ?? 0; ?><a class="admin-calendar-day <?= $date === $selected ? 'is-selected' : '' ?> <?= $date === $todayLocal ? 'is-today' : '' ?>" role="gridcell" aria-label="<?= h($date . ', ' . $total . ' appointments') ?>" aria-current="<?= $date === $todayLocal ? 'date' : 'false' ?>" href="<?= h($baseUrl . '?month=' . $monthInput . '&date=' . $date) ?>"><span><?= $day ?></span><?php if ($total): ?><small><?= $confirmed ?> booked</small><?php endif; ?></a><?php endfor; ?></div></div>
			<div class="day-bookings-heading"><div><p class="eyebrow">Daily agenda</p><h2><?= h((new DateTimeImmutable($selected, $timezone))->format('l, F j')) ?></h2></div><span><?= count($dayBookings) ?> appointment<?= count($dayBookings) === 1 ? '' : 's' ?></span></div>
			<?php if (!$dayBookings): ?><div class="empty-admin">Nothing booked on this day.</div><?php else: ?><div class="appointment-list">
				<?php foreach ($dayBookings as $booking): $localStart = (new DateTimeImmutable($booking['starts_at_utc'], $utc))->setTimezone(new DateTimeZone($booking['timezone'])); $localEnd = (new DateTimeImmutable($booking['ends_at_utc'], $utc))->setTimezone(new DateTimeZone($booking['timezone'])); ?>
					<article class="appointment-item"><div class="appointment-time"><strong><?= h($localStart->format('g:i A')) ?></strong><span><?= h($booking['timezone']) ?></span></div><div class="appointment-details"><div class="appointment-title"><h3><?= h($booking['customer_name']) ?></h3><span class="status-pill status-<?= h($booking['status']) ?>"><?= h(str_replace('_', ' ', $booking['status'])) ?></span></div><p><?= h($booking['service_name_snapshot']) ?> · <?= h($localEnd->format('g:i A')) ?> · <?= h($booking['reference']) ?></p><p><a href="mailto:<?= h($booking['customer_email']) ?>"><?= h($booking['customer_email']) ?></a><?= $booking['customer_phone'] ? ' · ' . h($booking['customer_phone']) : '' ?></p><?php if ($booking['customer_note']): ?><p class="customer-note"><?= nl2br(h($booking['customer_note'])) ?></p><?php endif; ?>
						<div class="appointment-actions"><?php if (in_array($booking['status'], ['confirmed', 'cancelled'], true)): ?><form method="post" class="status-form"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int) $booking['id'] ?>"><input type="hidden" name="return_date" value="<?= h($selected) ?>"><select name="status" aria-label="Status for <?= h($booking['reference']) ?>"><?php foreach (['confirmed','cancelled','completed','no_show'] as $status): ?><option value="<?= $status ?>" <?= $booking['status'] === $status ? 'selected' : '' ?>><?= h(ucwords(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select><button class="text-action" type="submit">Update status</button></form><?php endif; ?><?php if ($booking['status'] === 'confirmed'): ?><form class="reschedule-form" method="post" data-service-id="<?= (int) $booking['service_id'] ?>" data-booking-id="<?= (int) $booking['id'] ?>"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="reschedule"><input type="hidden" name="id" value="<?= (int) $booking['id'] ?>"><input type="hidden" name="return_date" value="<?= h($selected) ?>"><label><span class="sr-only">New appointment date</span><input type="date" name="date" min="<?= $todayLocal ?>" required></label><label><span class="sr-only">New appointment time</span><select name="starts_at" required disabled><option value="">Choose date first</option></select></label><button class="text-action" type="submit">Reschedule</button></form><?php endif; ?></div>
					</div></article>
				<?php endforeach; ?>
			</div><?php endif; ?>
		<?php endif; ?>
	</main>
	<script id="booking-admin-config" type="application/json"><?= $csrfJson ?></script>
</body>
</html>