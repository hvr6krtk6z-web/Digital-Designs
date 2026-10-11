<?php
declare(strict_types=1);

admin_session_start();
$db = app_db();
$errors = [];
$notice = '';
$adminCount = (int) $db->query('SELECT COUNT(*) FROM admins')->fetchColumn();
$ipHash = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . __DIR__);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	if (!valid_csrf()) {
		$errors[] = 'Your session expired. Refresh the page and try again.';
	} else {
		$action = (string) ($_POST['action'] ?? '');
		if ($action === 'logout') {
			$_SESSION = [];
			session_regenerate_id(true);
			header('Location: /admin', true, 303);
			exit;
		}

		if ($action === 'setup' && $adminCount === 0) {
			$tokenPath = app_storage_path() . '/initial-setup-token';
			$setupToken = is_file($tokenPath) ? trim((string) file_get_contents($tokenPath)) : '';
			$providedToken = (string) ($_POST['setup_token'] ?? '');
			$username = trim((string) ($_POST['username'] ?? ''));
			$password = (string) ($_POST['password'] ?? '');
			if ($setupToken === '' || !hash_equals($setupToken, $providedToken)) {
				$errors[] = 'The one-time setup token is missing or invalid.';
			}
			if (!preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $username)) {
				$errors[] = 'Use a username of 3–40 letters, numbers, dots, dashes, or underscores.';
			}
			if (strlen($password) < 14) {
				$errors[] = 'Choose a password with at least 14 characters.';
			}
			if (!$errors) {
				$insert = $db->prepare('INSERT INTO admins (username, password_hash) VALUES (:username, :hash)');
				$insert->execute(['username' => $username, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
				@unlink($tokenPath);
				session_regenerate_id(true);
				$_SESSION['admin_id'] = (int) $db->lastInsertId();
				$_SESSION['admin_username'] = $username;
				header('Location: /admin', true, 303);
				exit;
			}
		}

		if ($action === 'login' && $adminCount > 0 && empty($_SESSION['admin_id'])) {
			$attemptQuery = $db->prepare('SELECT attempts, updated_at FROM login_attempts WHERE ip_hash = :ip');
			$attemptQuery->execute(['ip' => $ipHash]);
			$attempt = $attemptQuery->fetch(PDO::FETCH_ASSOC);
			$blocked = $attempt && (int) $attempt['attempts'] >= 5 && time() - (int) $attempt['updated_at'] < 900;
			if ($blocked) {
				$errors[] = 'Too many sign-in attempts. Wait 15 minutes and try again.';
			} else {
				$username = trim((string) ($_POST['username'] ?? ''));
				$query = $db->prepare('SELECT id, username, password_hash FROM admins WHERE username = :username');
				$query->execute(['username' => $username]);
				$admin = $query->fetch(PDO::FETCH_ASSOC);
				if ($admin && password_verify((string) ($_POST['password'] ?? ''), $admin['password_hash'])) {
					$db->prepare('DELETE FROM login_attempts WHERE ip_hash = :ip')->execute(['ip' => $ipHash]);
					session_regenerate_id(true);
					$_SESSION['admin_id'] = (int) $admin['id'];
					$_SESSION['admin_username'] = $admin['username'];
					header('Location: /admin', true, 303);
					exit;
				}
				$attempts = $attempt && time() - (int) $attempt['updated_at'] < 900 ? (int) $attempt['attempts'] + 1 : 1;
				$saveAttempt = $db->prepare('INSERT INTO login_attempts (ip_hash, attempts, updated_at) VALUES (:ip, :attempts, :time) ON CONFLICT(ip_hash) DO UPDATE SET attempts = excluded.attempts, updated_at = excluded.updated_at');
				$saveAttempt->execute(['ip' => $ipHash, 'attempts' => $attempts, 'time' => time()]);
				$errors[] = 'The username or password is incorrect.';
			}
		}

		if (in_array($action, ['branding_favicon', 'branding_logo'], true) && !empty($_SESSION['admin_id'])) {
			$kind = substr($action, 9);
			$label = $kind === 'favicon' ? 'Favicon' : 'Logo';
			$file = $_FILES[$kind] ?? ['error' => UPLOAD_ERR_NO_FILE];
			if (isset($_POST['remove'])) {
				branding_remove($db, $kind);
				$notice = $label . ' removed. The default is now in use.';
			} elseif ($error = branding_save_upload($db, $kind, $file)) {
				$errors[] = $error;
			} elseif ($file['error'] === UPLOAD_ERR_OK) {
				$notice = $label . ' updated.';
			} else {
				$errors[] = 'Choose a ' . strtolower($label) . ' file to upload.';
			}
		}

		if ($action === 'save' && !empty($_SESSION['admin_id'])) {
			$values = [
				'title' => trim((string) ($_POST['title'] ?? '')),
				'description' => trim((string) ($_POST['description'] ?? '')),
				'canonical_url' => trim((string) ($_POST['canonical_url'] ?? '')),
				'social_title' => trim((string) ($_POST['social_title'] ?? '')),
				'social_description' => trim((string) ($_POST['social_description'] ?? '')),
				'social_image' => trim((string) ($_POST['social_image'] ?? '')),
				'indexing_enabled' => isset($_POST['indexing_enabled']) ? '1' : '0',
			];
			if ($values['title'] === '' || strlen($values['title']) > 70) {
				$errors[] = 'Page title is required and must be 70 characters or fewer.';
			}
			if ($values['description'] === '' || strlen($values['description']) > 160) {
				$errors[] = 'Meta description is required and must be 160 characters or fewer.';
			}
			if (strlen($values['social_title']) > 70 || $values['social_title'] === '') {
				$errors[] = 'Social title is required and must be 70 characters or fewer.';
			}
			if (strlen($values['social_description']) > 200 || $values['social_description'] === '') {
				$errors[] = 'Social description is required and must be 200 characters or fewer.';
			}
			foreach (['canonical_url' => $values['canonical_url'], 'social_image' => $values['social_image']] as $label => $url) {
				if ($url === '') {
					continue;
				}
				$scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
				if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
					$errors[] = $label === 'canonical_url' ? 'Canonical URL must be a valid HTTP or HTTPS URL.' : 'Social image must be a valid HTTP or HTTPS URL.';
				}
			}
			if (!$errors) {
				$save = $db->prepare('INSERT INTO settings (name, value) VALUES (:name, :value) ON CONFLICT(name) DO UPDATE SET value = excluded.value');
				foreach ($values as $name => $value) {
					$save->execute(['name' => $name, 'value' => $value]);
				}
				$notice = 'SEO settings saved.';
			}
		}
	}
}

$isLoggedIn = !empty($_SESSION['admin_id']);
$settings = setting_values($db);
$csrf = csrf_token();
?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Fieldnote Booking | Site Admin</title>
	<link rel="stylesheet" href="/admin.css">
	<?= branding_head($settings) ?>
</head>
<body class="admin-page">
	<header class="admin-topbar">
		<a class="admin-brand" href="/" target="_blank" rel="noreferrer"><?= branding_mark($settings, 'admin-mark') ?><span>Fieldnote Booking <small>Site admin</small></span></a>
		<?php if ($isLoggedIn): ?>
			<div class="admin-account"><span><?= h((string) $_SESSION['admin_username']) ?></span><form method="post"><input type="hidden" name="csrf_token" value="<?= h($csrf) ?>"><button class="text-button" name="action" value="logout">Sign out</button></form></div>
		<?php endif; ?>
	</header>
	<?php if ($isLoggedIn): ?>
	<nav class="admin-nav" aria-label="Admin sections">
		<a href="/admin" aria-current="page">SEO manager</a>
		<a href="/admin/bookings">Appointments</a>
		<a href="/admin/availability">Availability</a>
		<a href="/admin/services">Services</a>
		<a href="/admin/calendar">Google Calendar</a>
		<a href="/admin/pages">Page editor</a>
	</nav>
	<?php endif; ?>
	<main class="admin-main">
		<?php if (!$adminCount): ?>
			<section class="auth-panel">
				<p class="eyebrow">First-time setup</p>
				<h1>Create your admin account</h1>
				<p class="muted">This one-time setup is available only until the first account is created.</p>
				<?php if ($errors): ?><div class="alert" role="alert"><?php foreach ($errors as $error): ?><p><?= h($error) ?></p><?php endforeach; ?></div><?php endif; ?>
				<form method="post" class="field-stack">
					<input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
					<input type="hidden" name="action" value="setup">
					<label>One-time setup token<input name="setup_token" type="password" autocomplete="off" required></label>
					<label>Username<input name="username" autocomplete="username" required minlength="3" maxlength="40" pattern="[a-zA-Z0-9._-]+"></label>
					<label>Password<input name="password" type="password" autocomplete="new-password" required minlength="14"></label>
					<button class="primary-button" type="submit">Create account</button>
				</form>
			</section>
		<?php elseif (!$isLoggedIn): ?>
			<section class="auth-panel">
				<p class="eyebrow">Private access</p>
				<h1>Sign in</h1>
				<?php if ($errors): ?><div class="alert" role="alert"><?php foreach ($errors as $error): ?><p><?= h($error) ?></p><?php endforeach; ?></div><?php endif; ?>
				<form method="post" class="field-stack">
					<input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
					<input type="hidden" name="action" value="login">
					<label>Username<input name="username" autocomplete="username" required></label>
					<label>Password<input name="password" type="password" autocomplete="current-password" required></label>
					<button class="primary-button" type="submit">Sign in</button>
				</form>
			</section>
		<?php else: ?>
			<div class="dashboard-heading">
				<div><p class="eyebrow">Search appearance</p><h1>SEO manager</h1><p class="muted">Manage how the homepage appears in search results and when shared.</p></div>
				<a class="outline-button" href="/" target="_blank" rel="noreferrer">View website <span aria-hidden="true">↗</span></a>
			</div>
			<?php if ($notice): ?><div class="success" role="status"><?= h($notice) ?></div><?php endif; ?>
			<?php if ($errors): ?><div class="alert" role="alert"><?php foreach ($errors as $error): ?><p><?= h($error) ?></p><?php endforeach; ?></div><?php endif; ?>
			<form method="post" class="settings-form">
				<input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
				<input type="hidden" name="action" value="save">
				<section class="settings-section">
					<div class="section-title"><span class="section-icon">01</span><div><h2>Search result</h2><p>Homepage title and summary shown by search engines.</p></div></div>
					<div class="form-grid">
						<label class="field full">Page title <span class="field-hint">Recommended: up to 60 characters</span><input id="title" name="title" value="<?= h($settings['title']) ?>" maxlength="70" required><span class="counter" data-count="title" data-limit="70"></span></label>
						<label class="field full">Meta description <span class="field-hint">Summarize the page in one useful sentence</span><textarea id="description" name="description" rows="3" maxlength="160" required><?= h($settings['description']) ?></textarea><span class="counter" data-count="description" data-limit="160"></span></label>
						<label class="field full">Canonical URL <span class="field-hint">Leave blank to omit the canonical tag</span><input type="url" name="canonical_url" value="<?= h($settings['canonical_url']) ?>" placeholder="https://example.com/"></label>
					</div>
				</section>
				<section class="settings-section">
					<div class="section-title"><span class="section-icon">02</span><div><h2>Social sharing</h2><p>Preview title, description and image for shared links.</p></div></div>
					<div class="form-grid">
						<label class="field">Social title<input id="social_title" name="social_title" value="<?= h($settings['social_title']) ?>" maxlength="70" required><span class="counter" data-count="social_title" data-limit="70"></span></label>
						<label class="field">Social image URL <span class="field-hint">Use an absolute image URL</span><input type="url" name="social_image" value="<?= h($settings['social_image']) ?>" placeholder="https://example.com/share.jpg"></label>
						<label class="field full">Social description<textarea id="social_description" name="social_description" rows="3" maxlength="200" required><?= h($settings['social_description']) ?></textarea><span class="counter" data-count="social_description" data-limit="200"></span></label>
					</div>
				</section>
				<section class="settings-section indexing-section">
					<div class="section-title"><span class="section-icon">03</span><div><h2>Search visibility</h2><p>Control indexing and the generated robots.txt and sitemap.xml.</p></div></div>
					<label class="toggle-row"><span><strong>Allow search engines to index this site</strong><small>Disable while the site is a draft. This also updates robots.txt.</small></span><input type="checkbox" name="indexing_enabled" value="1" <?= $settings['indexing_enabled'] === '1' ? 'checked' : '' ?>><span class="switch" aria-hidden="true"></span></label>
					<div class="generated-links"><a href="/robots.txt" target="_blank" rel="noreferrer">robots.txt ↗</a><a href="/sitemap.xml" target="_blank" rel="noreferrer">sitemap.xml ↗</a></div>
				</section>
				<div class="form-actions"><span class="save-note">Changes are saved to the local SQLite database.</span><button class="primary-button" type="submit">Save SEO settings</button></div>
			</form>
			<?php foreach (['favicon' => ['04', 'Favicon', 'Shown in browser tabs and bookmarks. PNG, JPG, WebP or ICO, up to 512 KB. Square images work best.', '.png,.jpg,.jpeg,.webp,.ico'], 'logo' => ['05', 'Logo', 'Replaces the "F/" mark in the site header, booking page and admin. PNG, JPG or WebP, up to 1 MB. Square images work best.', '.png,.jpg,.jpeg,.webp']] as $kind => [$number, $label, $hint, $accept]): ?>
			<form method="post" enctype="multipart/form-data" class="settings-form">
				<input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
				<input type="hidden" name="action" value="branding_<?= h($kind) ?>">
				<section class="settings-section">
					<div class="section-title"><span class="section-icon"><?= h($number) ?></span><div><h2><?= h($label) ?></h2><p><?= h($hint) ?></p></div></div>
					<div class="form-grid">
						<div class="field">
							<?php if ($kind === 'favicon' || branding_file($settings, 'logo') !== ''): ?><img src="<?= h(branding_url($settings, $kind)) ?>" alt="Current <?= h(strtolower($label)) ?>" width="48" height="48" style="object-fit:contain;margin:.4rem 0"><?php endif; ?>
							<input type="file" name="<?= h($kind) ?>" accept="<?= h($accept) ?>" aria-label="Upload <?= h(strtolower($label)) ?>">
						</div>
					</div>
				</section>
				<div class="form-actions"><span class="save-note"><?= branding_file($settings, $kind) !== '' ? 'A custom ' . h(strtolower($label)) . ' is in use.' : 'The default ' . h(strtolower($label)) . ' is in use.' ?></span><span><?php if (branding_file($settings, $kind) !== ''): ?><button class="outline-button" type="submit" name="remove" value="1" formnovalidate>Remove</button> <?php endif; ?><button class="primary-button" type="submit">Upload <?= h(strtolower($label)) ?></button></span></div>
			</form>
			<?php endforeach; ?>
		<?php endif; ?>
	</main>
	<script src="/admin.js" defer></script>
</body>
</html>