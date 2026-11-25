# FAQ

**Who can use this package?**
Any Laravel 11–13 (or plain PHP 8.2+) system that has a key for the IbnKhafagah CRM ticket API – systems built by
IbnKhafagah Software, and systems built by other developers for clients whose support runs through it. The
key is issued per connected system by the provider.

**Is it a helpdesk inside my app?**
No local copy of tickets is kept. Tickets live in the provider's CRM (SLA, assignment, internal notes, e-mail, client
portal); your app shows and creates them through the API. That keeps everything consistent and costs no database
tables on your side.

**What happens when the internet or the CRM is down?**
Reading tickets shows a friendly "support unavailable" message. A new ticket is kept in the outbox (with its
attachments) and sent automatically by `support:flush` – once, thanks to `external_reference`.

**My server has no public address (on-premise, behind a firewall or Zero Trust).**
Leave `SUPPORT_WEBHOOK_SECRET` empty: `support:sync` reads the CRM change log every 5 minutes, outbound only.

**Can I use my own UI?**
Yes – every route answers JSON. See [JSON mode](json-api.md) (React example included), or publish and restyle the
Blade views ([Screens](screens.md)).

**Arabic?**
Arabic and English translations ship with the package; the screens switch to right-to-left automatically for Arabic.

**Who sees which tickets?**
A user sees only their own tickets. Users who pass your `support-admin` gate see every ticket of your system.

**What if our support plan ends?**
The CRM marks the system *suspended*: new technical tickets are refused with a renewal link, billing tickets are still
accepted, and open tickets can still be followed. Revoked systems can only read why.

**How do I test without a CRM?**
Bind a fake `Transport` – see [Testing](testing.md).
