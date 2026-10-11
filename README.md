# Fieldnote Basic

A responsive, single-page creative studio website with a password-protected admin area for homepage SEO settings. Built with PHP and SQLite, with no Composer dependencies or JavaScript build step.

## Features

- Responsive homepage with studio introduction, services, sample projects, and contact link
- Admin dashboard for page title, meta description, canonical URL, and social sharing metadata
- Search indexing control with generated `robots.txt` and `sitemap.xml`
- Separate favicon and logo uploads in the admin, with a built-in default favicon
- SQLite-backed settings and a one-time, command-line-generated admin setup token
- CSRF protection, secure session cookies, password hashing, and login throttling

Fieldnote Basic does not include appointment booking or calendar management.

## Requirements

- PHP 8.1 or newer
- PDO SQLite extension
- Apache with `mod_rewrite`, or a web server configured to route requests through `router.php`

## Run locally

From the project directory, start PHP's built-in development server:

```sh
php -S localhost:8000 router.php
```

Open <http://localhost:8000> to view the site. Keep the server running while using the admin.

## Create the admin account

1. In a second terminal at the project directory, run:

   ```sh
   php setup-token.php
   ```

2. Open <http://localhost:8000/admin>, enter the one-time token printed by the command, and create an administrator account with a unique password of at least 14 characters.

The setup token is deleted after the account is created. The default SQLite database is stored under the ignored `storage/` directory. For deployment, set `APP_STORAGE_PATH` to a private directory outside the public web root, enable HTTPS, and keep the database backed up.

## SEO settings

The site starts with search indexing disabled. Set the production canonical URL before enabling indexing. SEO fields are applied to the homepage when it is served by PHP; opening `preview.html` directly bypasses the backend and uses the static defaults.

## Customize and deploy

See [README-template.md](README-template.md) for detailed instructions on replacing the demo brand, copy, imagery, colors, and email address. Demo projects and text are fictional. Replace remote image assets with files you have permission to use before resale or production use.

Review [LICENSE](LICENSE) before distributing the template. Fonts and other third-party assets may have separate terms.