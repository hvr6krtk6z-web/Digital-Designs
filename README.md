# Fieldnote Pro

Fieldnote Pro is the planned top tier of the Fieldnote template family: a creative studio website with online booking, customer management, automated emails, payments, and a business dashboard. Built with PHP and SQLite.

> **Status: in development.** The features below are planned and are not implemented yet. This branch currently contains the Fieldnote Basic starting point (homepage plus SEO admin). Everything in [Planned features](#planned-features) will be added here.

## The Fieldnote family

| | Basic | Booking | Pro (planned) |
| --- | :---: | :---: | :---: |
| Responsive homepage and SEO manager | ✓ | ✓ | ✓ |
| Logo and favicon uploads | ✓ | ✓ | ✓ |
| Online booking calendar | | ✓ | ✓ |
| Availability, services and page editor | | ✓ | ✓ |
| Google Calendar sync (one-way) | | ✓ | ✓ |
| Customer management (CRM) | | | ✓ |
| Advanced booking management | | | ✓ |
| Automated emails | | | ✓ |
| Payments and deposits | | | ✓ |
| Business dashboard and reports | | | ✓ |

## Planned features

### 1. Customer management (CRM)

- Customer directory and contact details
- Booking history and internal notes
- Search and filter customers
- Export customer records

### 2. Advanced booking management

- Day, week and month calendar views
- Reschedule and cancel appointments
- Configurable cancellation rules
- Booking status management
- Appointment buffers and blocked periods

### 3. Automated communications

- Booking confirmations and cancellations
- Configurable appointment reminders
- Custom email templates and studio branding
- Notifications to the business owner

### 4. Payments and deposits

- Optional Stripe integration
- Deposits or full payments
- Payment status recorded against appointments
- Manual payment recording for cash or bank transfer

### 5. Business dashboard

- Upcoming appointments and outstanding actions
- Booking totals and cancellation rates
- Revenue reporting where payment data is available
- CSV exports for business records

## What works today

This branch runs as Fieldnote Basic: a responsive single-page studio site with a password-protected admin for homepage SEO settings, generated `robots.txt` and `sitemap.xml`, and a one-time command-line setup token. Appointment booking already exists in the separate Fieldnote Booking template.

## Requirements

- PHP 8.1 or newer
- PDO SQLite extension
- Apache with `mod_rewrite`, or a web server configured to route requests through `router.php`

## Run locally

```sh
php -S localhost:8000 router.php
```

Open <http://localhost:8000>. To create the admin account, run `php setup-token.php` in a second terminal, then enter the token at <http://localhost:8000/admin> and choose a unique password of at least 14 characters. For deployment, set `APP_STORAGE_PATH` to a private directory outside the public web root and enable HTTPS.

See [README-template.md](README-template.md) for customization and deployment notes, and review [LICENSE](LICENSE) before distributing the template.
