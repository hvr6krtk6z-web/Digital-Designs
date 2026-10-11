<?php
declare(strict_types=1);

admin_session_start();
$db = app_db();
$adminCount = (int) $db->query('SELECT COUNT(*) FROM admins')->fetchColumn();
if (empty($_SESSION['admin_id'])) {
	header('Location: /admin', true, 303);
	exit;
}

$fields = [
	'brand_line_1' => ['Brand name · line 1', 'text', 40],
	'brand_line_2' => ['Brand name · line 2', 'text', 40],
	'hero_eyebrow' => ['Hero label', 'text', 80],
	'hero_title_1' => ['Hero heading · line 1', 'text', 70],
	'hero_title_2' => ['Hero heading · line 2', 'text', 70],
	'hero_description' => ['Hero description', 'textarea', 240],
	'hero_button' => ['Hero button', 'text', 40],
	'hero_image' => ['Hero image URL', 'url', 500],
	'intro_eyebrow' => ['Introduction label', 'text', 80],
	'intro_title_1' => ['Introduction heading · line 1', 'text', 70],
	'intro_title_2' => ['Introduction heading · line 2', 'text', 70],
	'intro_description' => ['Introduction text', 'textarea', 400],
	'services_title' => ['Sessions section heading', 'text', 70],
	'services_description' => ['Sessions section introduction', 'textarea', 240],
	'projects_eyebrow' => ['Projects label', 'text', 80],
	'projects_title' => ['Projects heading', 'text', 70],
	'projects_description' => ['Projects introduction', 'textarea', 240],
	'project_1_title' => ['Project one · name', 'text', 70],
	'project_1_caption' => ['Project one · caption', 'text', 100],
	'project_1_image' => ['Project one · image URL', 'url', 500],
	'project_2_title' => ['Project two · name', 'text', 70],
	'project_2_caption' => ['Project two · caption', 'text', 100],
	'project_2_image' => ['Project two · image URL', 'url', 500],
	'contact_eyebrow' => ['Contact label', 'text', 80],
	'contact_title_1' => ['Contact heading · line 1', 'text', 70],
	'contact_title_2' => ['Contact heading · line 2', 'text', 70],
	'contact_description' => ['Contact text', 'textarea', 240],
	'contact_button' => ['Contact button', 'text', 50],
	'contact_email' => ['Contact email', 'email', 254],
	'footer_label' => ['Footer descriptor', 'text', 100],
];
$errors = [];
$notice = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!valid_csrf()) {
		$errors[] = 'Your session expired. Refresh the page and try again.';
	} else {
		$values = [];
		foreach ($fields as $key => [$label, $type, $limit]) {
			$value = trim((string) ($_POST[$key] ?? ''));
			if ($value === '' || strlen($value) > $limit) {
				$errors[] = $label . ' is required and must be ' . $limit . ' characters or fewer.';
				continue;
			}
			if ($type === 'url' && (!filter_var($value, FILTER_VALIDATE_URL) || strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https')) {
				$errors[] = $label . ' must be a valid HTTPS URL.';
				continue;
			}
			if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
				$errors[] = $label . ' must be a valid email address.';
				continue;
			}
			$values[$key] = $value;
		}
		if (!$errors) {
			$save = $db->prepare('INSERT INTO page_content (content_key, content_value) VALUES (:key, :value) ON CONFLICT(content_key) DO UPDATE SET content_value = excluded.content_value');
			foreach ($values as $key => $value) {
				$save->execute(['key' => $key, 'value' => $value]);
			}
			$notice = 'Homepage content saved.';
		}
	}
}

$content = $db->query('SELECT content_key, content_value FROM page_content')->fetchAll(PDO::FETCH_KEY_PAIR);
$csrf = csrf_token();
?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Page editor | Fieldnote Booking</title>
	<link rel="stylesheet" href="/admin.css">
	<?= branding_head($settings) ?>
</head>
<body class="admin-page">
	<header class="admin-topbar">
		<a class="admin-brand" href="/" target="_blank" rel="noreferrer"><?= branding_mark($settings, 'admin-mark') ?><span>Fieldnote Booking <small>Site admin</small></span></a>
		<?php if (!empty($_SESSION['admin_id'])): ?><div class="admin-account"><span><?= h((string) $_SESSION['admin_username']) ?></span><form method="post" action="/admin"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><button class="text-button" name="action" value="logout">Sign out</button></form></div><?php endif; ?>
	</header>
	<nav class="admin-nav" aria-label="Admin sections"><a href="/admin">SEO manager</a><a href="/admin/bookings">Appointments</a><a href="/admin/availability">Availability</a><a href="/admin/services">Services</a><a href="/admin/calendar">Google Calendar</a><a href="/admin/pages" aria-current="page">Page editor</a></nav>
	<main class="admin-main">
		<div class="dashboard-heading"><div><p class="eyebrow">Homepage content</p><h1>Page editor</h1><p class="muted">Edit the public homepage copy and images. HTML is shown as text, never executed.</p></div><a class="outline-button" href="/" target="_blank" rel="noreferrer">View website <span aria-hidden="true">↗</span></a></div>
		<?php if ($notice): ?><div class="success" role="status"><?= h($notice) ?></div><?php endif; ?>
		<?php if ($errors): ?><div class="alert" role="alert"><?php foreach ($errors as $error): ?><p><?= h($error) ?></p><?php endforeach; ?></div><?php endif; ?>
		<form method="post" class="settings-form">
			<input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
			<section class="settings-section"><div class="section-title"><span class="section-icon">01</span><div><h2>Brand and hero</h2><p>Brand name, headline and first impression.</p></div></div><div class="form-grid">
				<?php foreach (array_slice($fields, 0, 8, true) as $key => [$label, $type, $limit]): ?>
					<label class="field <?= $type === 'textarea' ? 'full' : '' ?>"><?= h($label) ?><span class="field-hint">Up to <?= $limit ?> characters</span><?php if ($type === 'textarea'): ?><textarea name="<?= h($key) ?>" rows="3" maxlength="<?= $limit ?>" required><?= h($content[$key] ?? '') ?></textarea><?php else: ?><input type="<?= h($type) ?>" name="<?= h($key) ?>" value="<?= h($content[$key] ?? '') ?>" maxlength="<?= $limit ?>" required><?php endif; ?></label>
				<?php endforeach; ?>
			</div></section>
			<section class="settings-section"><div class="section-title"><span class="section-icon">02</span><div><h2>Introduction and sessions</h2><p>The studio introduction and the appointment section heading.</p></div></div><div class="form-grid">
				<?php foreach (array_slice($fields, 8, 6, true) as $key => [$label, $type, $limit]): ?>
					<label class="field <?= $type === 'textarea' ? 'full' : '' ?>"><?= h($label) ?><span class="field-hint">Up to <?= $limit ?> characters</span><?php if ($type === 'textarea'): ?><textarea name="<?= h($key) ?>" rows="3" maxlength="<?= $limit ?>" required><?= h($content[$key] ?? '') ?></textarea><?php else: ?><input type="<?= h($type) ?>" name="<?= h($key) ?>" value="<?= h($content[$key] ?? '') ?>" maxlength="<?= $limit ?>" required><?php endif; ?></label>
				<?php endforeach; ?>
			</div><p class="editor-help">Appointment names, durations and prices are managed separately in <a href="/admin/services">Services</a>.</p></section>
			<section class="settings-section"><div class="section-title"><span class="section-icon">03</span><div><h2>Selected work</h2><p>Update the sample projects and their images.</p></div></div><div class="form-grid">
				<?php foreach (array_slice($fields, 14, 9, true) as $key => [$label, $type, $limit]): ?>
					<label class="field <?= $type === 'textarea' ? 'full' : '' ?>"><?= h($label) ?><span class="field-hint"><?= $type === 'url' ? 'Use an HTTPS image URL' : 'Up to ' . $limit . ' characters' ?></span><?php if ($type === 'textarea'): ?><textarea name="<?= h($key) ?>" rows="3" maxlength="<?= $limit ?>" required><?= h($content[$key] ?? '') ?></textarea><?php else: ?><input type="<?= h($type) ?>" name="<?= h($key) ?>" value="<?= h($content[$key] ?? '') ?>" maxlength="<?= $limit ?>" required><?php endif; ?></label>
				<?php endforeach; ?>
			</div></section>
			<section class="settings-section"><div class="section-title"><span class="section-icon">04</span><div><h2>Contact and footer</h2><p>Public contact details and closing line.</p></div></div><div class="form-grid">
				<?php foreach (array_slice($fields, 23, null, true) as $key => [$label, $type, $limit]): ?>
					<label class="field <?= $type === 'textarea' ? 'full' : '' ?>"><?= h($label) ?><span class="field-hint">Up to <?= $limit ?> characters</span><?php if ($type === 'textarea'): ?><textarea name="<?= h($key) ?>" rows="3" maxlength="<?= $limit ?>" required><?= h($content[$key] ?? '') ?></textarea><?php else: ?><input type="<?= h($type) ?>" name="<?= h($key) ?>" value="<?= h($content[$key] ?? '') ?>" maxlength="<?= $limit ?>" required><?php endif; ?></label>
				<?php endforeach; ?>
			</div></section>
			<div class="form-actions"><span class="save-note">Homepage edits save to the Booking SQLite database.</span><button class="primary-button" type="submit">Save page</button></div>
		</form>
	</main>
</body>
</html>