# Backend

Production API: `https://api.mahala.app/public/api`. The repo root sits inside the web root and Laravel is served from `public/`, so the root `.htaccess` denies dotfiles (`.env`).

Run tests with `php artisan test` (phpunit.xml uses SQLite `:memory:`). Never reset, seed or wipe a database during deployment. Do not commit `.env`, `vendor`, `composer.phar` or generated caches.

# Deployment

After pushing, redeploy by opening (plain-text streamed output):

- `https://api.mahala.app/public/redeploy.php` — `git pull --ff-only`, `composer install --no-dev`, clears the config cache, runs pending migrations, clears route/view/application caches, `config:cache`

Redeploy runs `php artisan migrate --force --no-interaction` under its deployment lock and stops on failure. Keep one-time destructive maintenance operations in `database/manual-migrations`, outside the automatic migration path.
