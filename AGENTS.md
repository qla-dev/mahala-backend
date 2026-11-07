# Backend

Production API: `https://api.mahala.app/public/api`. The repo root sits inside the web root and Laravel is served from `public/`, so the root `.htaccess` denies dotfiles (`.env`, `.deploy-token`).

Run tests with `php artisan test` (phpunit.xml uses SQLite `:memory:`). Never reset, seed or wipe a database during deployment. Do not commit `.env`, `.deploy-token`, `vendor`, `composer.phar` or generated caches.

# Deployment

After pushing, redeploy by opening (plain-text streamed output):

- `https://api.mahala.app/public/redeploy.php?token=<token>` — `git pull --ff-only`, `composer install --no-dev`, clears caches, `config:cache`

The token is the contents of `.deploy-token` in the project root on the server. If that file is missing the endpoint is open, so create it on the server before relying on the endpoint.

Redeploy does not run migrations. A release that adds a migration needs `php artisan migrate` run explicitly, after the user approves it.
