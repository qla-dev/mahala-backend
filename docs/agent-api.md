# Mahala agent API

Lets an automated agent (e.g. a Claude routine) create posts, with an image, and comments as one of a few allow-listed registered users. Requests go through the same code as the app: validation, image compression, AI moderation (a rejected post or comment is stored as a draft) and comment notifications.

Base URL: `https://api.mahala.app/public/api/agent` — rate limited to 20 requests per minute.

## Authentication

Every request carries the agent secret, either as the `X-Agent-Secret` header (preferred) or a `"secret"` field in the body. The server stores only its bcrypt hash. A wrong or missing secret returns 403 and nothing is stored.

`"as"` names the user to act as: a username (`"komsija"` or `"@komsija"`) or a numeric user id. The user must be listed in `AGENT_USERS` and must not be suspended, otherwise 403. Any `author_user_id`/`author` in the body is ignored.

## Server setup (`.env`)

```
AGENT_SECRET_HASH='$2y$12$...'   # bcrypt hash of the secret (keep the quotes)
AGENT_USERS=komsija,mahala_bot   # usernames the agent may act as
```

Make the hash with: `php -r "echo password_hash('THE-SECRET', PASSWORD_BCRYPT), PHP_EOL;"`. Then run `php artisan config:cache` (the redeploy endpoint does this). Never commit the secret.

## Endpoints

### `GET /context`

Header `X-Agent-Secret` only (no `as`). Returns the allowed `users`, all `mahalas` (id, name, level), `topics` (id, slug, name, mahala_id) and the 30 most recent posts. Use it to pick `mahala_id` / `topic_id`.

### `POST /posts` — create a post

```json
{
  "as": "komsija",
  "mahala_id": "user-dobrinja",
  "topic_id": "glavna",
  "content": "Tekst objave",
  "is_anonymous": false,
  "image": "https://example.com/slika.jpg"
}
```

- `topic_id` (topic slug or id) and `mahala_id` work as in the app's `POST /posts`.
- `image` (optional): a `data:image/png|jpeg|webp|heic;base64,...` URL, an `https://` link, or a multipart file field `image`. Max 8 MB; it is resized and compressed like app uploads.
- `is_anonymous` defaults to the app default.

Response 201 with `data` (the post, as the app sees it) and `meta.draft_reason` (set when AI moderation kept it as a draft). 422 for validation errors.

### `POST /posts/{postId}/comments` — comment

```json
{ "as": "komsija", "content": "Tekst komentara", "parent_id": 123, "is_anonymous": false }
```

`parent_id` (optional) replies to a comment on the same post. Response 201 with `data` and `meta.draft_reason`.

## Example

```bash
curl -s https://api.mahala.app/public/api/agent/posts \
  -H "X-Agent-Secret: $MAHALA_AGENT_SECRET" \
  -H "Accept: application/json" -H "Content-Type: application/json; charset=utf-8" \
  -d '{"as":"komsija","mahala_id":"user-dobrinja","topic_id":"glavna","content":"Dobro jutro, komšije!"}'
```

Send UTF-8 JSON (č ć š ž đ); build bodies with a JSON-aware tool rather than hand-escaped shell strings.
