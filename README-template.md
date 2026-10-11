# Fieldnote Booking

A responsive creative-studio website with a real appointment calendar and a protected PHP/SQLite admin. Requires PHP 8.1+ with PDO SQLite. There is no Composer dependency or JavaScript build step.

## Run locally

From this directory, start the PHP development server:

```sh
php -S localhost:8000 router.php
```

Open <http://localhost:8000> for the website, <http://localhost:8000/book> to make a booking, and <http://localhost:8000/admin> for the admin sign-in or initial setup.

Before creating the first account, run `php setup-token.php` in a separate terminal and enter the one-time token in the setup form. The token is available only through the CLI and is deleted after account creation. Choose a unique password of at least 14 characters. Only one administrator account is supported.

The default SQLite database is stored in the ignored `storage/fieldnote-booking/` directory. Set `APP_STORAGE_PATH` to a private directory outside the public web root when deploying.

## Admin sections

- `/admin/bookings`: monthly calendar, daily agenda, status updates, cancellations and rescheduling
- `/admin/availability`: business timezone, weekly hours, date-specific closures or special hours, booking window, notice period and slot interval
- `/admin/services`: add, edit, pause or reactivate services, durations and buffers
- `/admin/pages`: edit homepage branding, copy, project examples, images and contact details
- `/admin`: SEO title and description, canonical URL, social metadata and indexing controls

Changing a schedule or date exception is rejected if it would conflict with a confirmed appointment. Bookings are stored as UTC instants with a business-timezone snapshot. SQLite write transactions prevent two guests from reserving the same slot, and service name/duration details are kept with each appointment.

## Booking behavior

Public bookings are confirmed immediately; the admin can cancel, complete, mark no-show, or reschedule them. Guests receive a confirmation reference and can download an `.ics` calendar event. This starter does not send email notifications, process payments, or sync with Google Calendar/Outlook. Connect those services separately if required.

## Search visibility

SEO settings are applied to the PHP-served homepage. The site starts with indexing disabled. Set the production canonical URL before enabling indexing. `/robots.txt` and `/sitemap.xml` are generated from the saved settings.

## Customize and deploy

Use `/admin/pages` to edit the homepage. Replace demo project imagery with assets you have permission to use; the sample images and Google Fonts are loaded remotely and require an internet connection. Review [LICENSE](LICENSE) before distribution. It grants one production site and prohibits redistributing the template source. Third-party assets remain under their own licenses.

Deploy on PHP 8.1+ with PDO SQLite and HTTPS. Apache can use the included `.htaccess`; other servers must route `/admin/*`, `/api/*`, `/book`, `/robots.txt`, `/sitemap.xml`, `/favicon.ico`, and `/brand/*` through `router.php`. Keep the storage directory outside the public web root, back it up, and configure the web server to serve static assets normally.