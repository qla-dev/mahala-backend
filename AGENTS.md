# Backend

Production API: `https://api.mahala.app/public/api`. The repo root sits inside the web root and Laravel is served from `public/`, so the root `.htaccess` denies dotfiles (`.env`).

Run tests with `php artisan test` (phpunit.xml uses SQLite `:memory:`). Never reset, seed or wipe a database during deployment. Do not commit `.env`, `vendor`, `composer.phar` or generated caches.

# Deployment

After pushing, redeploy by opening (plain-text streamed output):

- `https://api.mahala.app/public/redeploy.php` — `git pull --ff-only`, `composer install --no-dev`, `migrate --force`, clears caches, `config:cache`

Redeploy runs pending migrations (`php artisan migrate --force`), never `migrate:fresh`, reset or seeders. Because the script runs the version it had before `git pull`, a change to `redeploy.php` itself takes effect on the next redeploy.
