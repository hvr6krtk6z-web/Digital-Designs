# Fieldnote Basic template

A responsive single-page creative studio website with a PHP/SQLite admin area and SEO manager. Requires PHP 8.1+ with PDO SQLite enabled; no Composer or JavaScript build step is required.

## Run locally

Start the PHP development server from this folder:

```sh
php -S localhost:8000 router.php
```

Open `http://localhost:8000/admin`. Before creating the only admin account, run `php setup-token.php` in a separate terminal and enter its one-time token in the setup form. The token is stored in the private data directory and deleted after successful setup. Use a unique password of at least 14 characters. SQLite is created in the ignored `storage/` directory by default; set `APP_STORAGE_PATH` to a private directory outside the public web root when deploying.

## SEO manager

At `/admin`, set the homepage title, meta description, canonical URL, social sharing title/description/image, and search indexing preference. The server uses these settings in the public page's HTML and generates `/robots.txt` and `/sitemap.xml`. The site starts with indexing disabled; enable it only after setting the production canonical URL. The same page has separate uploads for the favicon (PNG, JPG, WebP or ICO, up to 512 KB) and the logo (PNG, JPG or WebP, up to 1 MB); uploads are stored in `storage/branding/` and served through `/brand/*` and `/favicon.ico`.

## Customize before delivery

1. Replace the demo studio name, page copy, services, project examples, footer details, and email address in `preview.html`.
2. Update the default SEO values in `bootstrap.php` if distributing a preconfigured copy.
3. Replace the remote Unsplash image URLs with images you have permission to use. The Google Fonts and Unsplash images need an internet connection.
4. Adjust the CSS variables in `preview.html` and `admin.css` to match the buyer's brand.
5. Deploy on a PHP host with PDO SQLite and Apache `mod_rewrite` support, or configure equivalent routes for `router.php`. Use HTTPS in production and keep `storage/` private and backed up.

Project names and copy are fictional demo content. The contact button uses `mailto:hello@example.com`; connect it to a hosted form provider if needed. The admin area supports one administrator account, created once from `/admin`.