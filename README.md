# Fieldnote Booking

A responsive creative studio website with a real appointment calendar and a password-protected admin area. Built with PHP and SQLite, with no Composer dependencies or JavaScript build step.

## Features

- Responsive homepage with services, sample projects, and a call to action that links to the booking calendar
- Public booking page at `/book` with service selection, live availability, instant confirmation, a reference code, and a downloadable `.ics` event
- Admin booking calendar with daily agenda, cancel, complete, no-show, and reschedule actions
- Availability settings: business timezone, weekly hours, date exceptions, booking window, notice period, and slot interval
- Services manager for names, durations, and buffers
- Homepage page editor for branding, copy, projects, images, and contact details
- Separate logo and favicon uploads, plus a SEO manager with generated `robots.txt` and `sitemap.xml`
- Double-booking prevention, booking rate limiting, a honeypot field, CSRF protection, secure session cookies, password hashing, and login throttling

Booking does not send email, take payments, or sync with Google Calendar or Outlook.

## Requirements

- PHP 8.1 or newer
- PDO SQLite extension
- Apache with `mod_rewrite`, or a web server configured to route requests through `router.php`

## Run locally

From the project directory, start PHP's built-in development server:

```sh
php -S localhost:8000 router.php
```

Open <http://localhost:8000> for the site, <http://localhost:8000/book> to make a booking, and <http://localhost:8000/admin> for the admin. Keep the server running while using the admin.

## Create the admin account

1. In a second terminal at the project directory, run:

   ```sh
   php setup-token.php
   ```

2. Open <http://localhost:8000/admin>, enter the one-time token printed by the command, and create an administrator account with a unique password of at least 14 characters.

The setup token is deleted after the account is created. The default SQLite database is stored under the ignored `storage/fieldnote-booking/` directory. For deployment, set `APP_STORAGE_PATH` to a private directory outside the public web root, enable HTTPS, and keep the database backed up.

## Admin sections

- `/admin/bookings`: calendar, agenda, and appointment status changes
- `/admin/availability`: hours, exceptions, notice, window, and slot interval
- `/admin/services`: add, edit, pause, or reactivate services
- `/admin/pages`: homepage content editor
- `/admin`: SEO settings and logo and favicon uploads

Schedule changes that would conflict with a confirmed appointment are rejected.

## SEO settings

The site starts with search indexing disabled. Set the production canonical URL before enabling indexing. SEO fields are applied to the homepage when it is served by PHP; opening `preview.html` directly bypasses the backend and uses the static defaults.

## Customize and deploy

See [README-template.md](README-template.md) for detailed instructions on running, customizing, and deploying the template, including web server routing. Demo projects and text are fictional. Replace remote image assets with files you have permission to use before resale or production use.

Review [LICENSE](LICENSE) before distributing the template. Fonts and other third-party assets may have separate terms.