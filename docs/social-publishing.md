# MAHALA social publishing

The API publishes one JPEG with a caption as a standalone post on Facebook Pages, Instagram, and LinkedIn organization pages. Instagram retrieves the JPEG from a temporary signed backend URL. The backend removes the staged JPEG when the request ends. LinkedIn receives the JPEG directly and the backend waits for image availability before publishing.

## Server configuration

Set these values in the server's `.env` and rebuild Laravel's configuration cache.

| Variable | Value |
| --- | --- |
| `SOCIAL_PUBLISH_TOKEN_SHA256` | Lowercase SHA-256 hex digest of a separate random 32-byte bearer secret. Give the raw secret only to the authorized publishing client through its secret store. |
| `META_PAGE_ID` | MAHALA Facebook Page ID. |
| `META_IG_USER_ID` | MAHALA Instagram professional account ID connected to that Facebook Page. |
| `META_PAGE_TOKEN` | Page access token for that Page with Meta publishing permissions, including `pages_manage_posts`, `pages_read_engagement`, `pages_show_list`, `instagram_basic`, and `instagram_content_publish`. |
| `META_GRAPH_VERSION` | Optional Graph API version. Defaults to `v27.0`. |
| `LINKEDIN_ORGANIZATION_ID` | Numeric ID of the LinkedIn organization page. |
| `LINKEDIN_ACCESS_TOKEN` | OAuth access token with `w_organization_social` for a member who can publish on the organization page and upload its images. The LinkedIn app needs Community Management API access. |
| `LINKEDIN_API_VERSION` | Optional LinkedIn Marketing API version. Defaults to `202611`. Set this to a supported version before the configured release sunsets. |

The public API must serve HTTPS and accept Meta's concurrent request for the signed JPEG while the publishing request is open. Keep the Laravel file cache writable and persistent across PHP requests. No database migration is required.

## Publish a photo

Export the chat-generated image as JPEG, between 320 and 1440 pixels wide, with a width-to-height ratio from 0.8 to 1.91 and a size of at most 8 MiB. Use a new UUID per intended publication, and keep it with the image and caption for retries.

```sh
curl --silent --show-error --fail-with-body \
  -H "Authorization: Bearer ${MAHALA_SOCIAL_PUBLISH_TOKEN}" \
  -H 'Accept: application/json' \
  -F "publication_id=${MAHALA_PUBLICATION_ID}" \
  --form-string "caption=${MAHALA_CAPTION}" \
  -F "image=@${MAHALA_JPEG};type=image/jpeg" \
  https://api.mahala.app/public/api/social/photos
```

Omitting `channels` selects Facebook and Instagram. Add `-F 'channels[]=linkedin'` for LinkedIn alone. To post to all three, pass `-F 'channels[]=facebook' -F 'channels[]=instagram' -F 'channels[]=linkedin'`. The JSON response reports each channel independently.

| Status | Meaning |
| --- | --- |
| `posted` | The provider returned a post or media ID. |
| `failed` | A step before publishing failed or the provider explicitly rejected publishing. Check the stage and provider code, then retry with the same UUID and content. |
| `unconfigured` | The required account ID or access token is missing. |
| `unknown` | The provider may have accepted the post. Verify the account before making another attempt with a different UUID. |

A retry with the same UUID skips channels already marked `posted` or `unknown` while the result is in the file cache (seven days). Changing the image or caption for an existing UUID returns HTTP 409. Keep the file cache intact while retrying, and verify each channel's status even when the HTTP request succeeds.
