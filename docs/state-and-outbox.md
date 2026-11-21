# State: cache, files and the outbox

What the package keeps on your side, and for how long.

## State: cache and files

**In the cache** (keys start with `support-client:`):

- the unread marks per user, and per user and ticket what they last saw (120 days);
- deleted-ticket tombstones (90 days), handled event ids (90 days), the short "processing" key while an event is handled (120 s) and the webhook replay key (10 minutes);
- the last known support status and categories (per language);
- the locks of `support:sync`, `support:flush` and of each user's marks, and the keys that limit repeated log warnings.

The dedupe keys, the tombstones and the status carry a short fingerprint of `SUPPORT_API_URL`.

**On disk, in `storage/app/support-client/`:**

- `cursor.json`: the sync's position (`after`), `initial_done`, the CRM fingerprint (`crm`), the ids already handled inside the overlap window (`handled`) and the event that failed last with its count (`failure`). It is written atomically.

Keep this folder across deploys (with release-folder deploys, in the shared `storage/`).
- `outbox/{uuid}/`: `entry.json` (the ticket exactly as it would have been sent: reporter, text, context) and `attachment-1…5`.
- `outbox/failed/{uuid}/`: tickets the CRM refused for good, with its reason.

**`php artisan cache:clear`** resets the badges to zero and forgets the kept status: the offline create form needs one online view again, and the reply rule reads the status once more. The sync does not repeat events after it (its handled ids live in `cursor.json`), but a webhook retry that arrives after the clear may be handled again: one more reason to keep listeners idempotent.

**First install.** The first sync reads the CRM's whole change log (up to 90 days). Until it reaches the end (`initial_done`), events older than 24 hours are applied quietly (the status, deletions) without unread marks or `TicketUpdated`, so a new install does not start with a badge full of old tickets or send notifications for them. A large log takes several runs (2,000 events each). A missing `cursor.json` starts the same way, and so does an unreadable one (with a warning in the log).

**Changing the CRM URL** (staging to production): the sync notices the new fingerprint in `cursor.json` and starts over as a first install (logged); the other CRM's dedupe keys and status are not used. The unread marks are not tied to a CRM, so the badge can still count tickets of the old one: opening such a number drops its mark (the new CRM answers `404`), and `php artisan cache:clear` drops them all with the rest of the cache. **The outbox is not tied to a CRM either**: send it (`support:flush`) before you switch, or its tickets go to the new CRM.

**The outbox and personal data.** The outbox holds users' names, e-mails, phones and roles, the ticket text, the error messages and up to 20 MB of files per ticket. `storage/app` must not be web-served: never link it into `public/`. Limits: **10 waiting tickets per user** (past that, the store answers "unavailable"), refused entries deleted after **30 days**, and folders a crash left half-written removed after an hour. Each user sees their own waiting and refused tickets at the top of their list. A `401` (a rotated key) or a `429` keeps every entry; other `4xx` answers move the entry to `failed/`; an attachment missing on disk is left out so the ticket still goes. To keep it elsewhere, bind your own in a service provider's `register()`: `$this->app->bind(Outbox::class, fn () => new Outbox('/secure/path'))`.

---
[← Documentation index](README.md)
