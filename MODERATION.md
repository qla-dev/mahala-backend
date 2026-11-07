# Moderation operations

Reports and support messages stay in the backend. No email is sent by these flows.

## Deploy

1. Deploy this backend and the accompanying frontend and mahala-web changes together. The mobile client needs a new build for App Review.
2. Run the new migration only with the owner's explicit approval, as required by AGENTS.md. Do not reset or seed the database.
3. Set `MODERATION_USER_IDS` to a comma-separated list of trusted existing user IDs. This grants access to the moderator API; ordinary users cannot list or resolve reports. Refresh Laravel's configuration cache after configuring it.
4. Enable `POST_AI_MODERATION_ENABLED=true` and configure `OPENROUTER_API_KEY` on the server. Disabled or unavailable checks now keep posts and comments as drafts and prevent new topics from being published. Do not claim filtering is operational until a real allowed/rejected text and image have been checked on the deployed server.
5. Verify `https://mahala.app/terms`, `/privacy`, and `/support` after deploying the web build. Support form submissions are stored in `support_requests`.

The updated mobile client expires saved authenticated sessions from before this terms version, so existing users must explicitly accept the updated agreement at their next login.

## Review every report within 24 hours

Assign a responsible moderator and coverage that includes nights and weekends. The software records a deadline; it does not replace a staffed moderation process or promise that someone has reviewed a report.

`GET /api/moderation/reports` requires a Sanctum token belonging to a configured moderator. Its `alerts` object contains unread, due-soon (next four hours), and overdue counts. Pending reports are ordered by their original 24-hour deadline and include evidence snapshots, so later content changes do not erase a report. Poll this backend queue regularly and act before the deadline. The legacy block endpoint also creates an unread report.

`PATCH /api/moderation/reports/{id}` accepts one of:

```json
{"action":"seen"}
{"action":"remove","note":"Confirmed harassment; reviewed by moderator."}
{"action":"dismiss","note":"Reviewed context; no violation found."}
```

Removing a confirmed violation also suspends the author, revokes all their API tokens, and removes their posts, comments and authored topics from public feeds. Suspended accounts cannot log in, reset a password to regain access, or make authenticated requests. Dismissal preserves the account and content. Reports are not automatic grounds for suspension; a moderator makes the decision.

The same operations are available to trusted server operators without a web account:

```sh
php artisan moderation:reports
php artisan moderation:review REPORT_ID --action=remove --reviewer=YOUR_NAME --note="Confirmed violation"
php artisan moderation:review REPORT_ID --action=dismiss --reviewer=YOUR_NAME --note="No violation"
```

Review incoming contacts through `GET /api/moderation/support`. After handling one, mark it resolved with `PATCH /api/moderation/support/{id}`. Access has the same moderator restriction. Contact email addresses are stored for replying; these endpoints do not send mail.

## App Review evidence

Record the release build on a physical iPhone against the deployed backend. Use two separate non-admin test accounts and prepared benign sample posts/comments.

1. Start logged out. Open the terms and privacy policy, show the no-tolerance policy, show that login/registration cannot continue unchecked, and accept the agreement. Cover Sign in with Apple/Google as well if shown.
2. Open another test account's post, choose **Prijavi**, enter a reason, and choose **Prijavi i Blokiraj**. Show successful submission and that all posts by that author disappear immediately.
3. With another test author, demonstrate the **Prijavi** action on a comment and show its author's comments disappear immediately.
4. Show the new unread report in the backend queue, and demonstrate removing the content and suspending the offending test account. Verify its existing token is revoked and a new login fails. Do not ban a real user for this demonstration.
5. Add the real recording link or attachment to App Review Information Notes, and include it in the reply to Apple. Do not claim a recording exists until it has been captured. This change does not submit a new version or reply to Apple.

Example notes after deployment and recording:

> Terms are presented before email, Apple and Google authentication. Open a post or comment and choose “Prijavi” → “Prijavi i Blokiraj” to report it and immediately hide that author's content. Reports create unread entries in our restricted backend moderation queue with a 24-hour deadline. Moderators review each report within 24 hours; confirmed violations remove content, suspend the offender and revoke their sessions. Contact support through Settings → Pomoć i sigurnost → Kontaktiraj MAHALA tim. Physical-device recording: INSERT_REAL_RECORDING_LINK.

## Validation

Run `php artisan test --filter='ContentModerationApiTest|AuthApiTest'` for reporting, blocking, consent, account suspension, evidence retention, and access-control checks. Tests use SQLite in memory and never migrate production.
