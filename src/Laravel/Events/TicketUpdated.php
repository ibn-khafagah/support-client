<?php

namespace IbnKhafagah\SupportClient\Laravel\Events;

/**
 * A change to a ticket of this system that the user did not make through
 * this app: a reply or a change by the support team, an auto-close, a reply
 * by e-mail, a deletion. Dispatched for ticket.created, ticket.updated,
 * ticket.replied and ticket.deleted events whose origin is not `api`, from
 * the webhook or from `support:sync`, once per event, after the unread
 * marks are in place. Not dispatched for old news: a late event about a
 * ticket deleted since, or (until the first sync has read the whole change
 * log) an event older than a day.
 *
 * Listen to it to send your own notifications. A ticket.replied carries the
 * reply's author in $reply['from']: `staff`, or `client` for the reporter's
 * own reply by e-mail or through the portal (which marks nothing unread;
 * you probably don't want to notify them about it either).
 *
 * Make the listener queued: the webhook answers the CRM right after
 * dispatching it. A listener that throws makes the event count as not
 * handled: the webhook answers 500 and the CRM retries, and `support:sync`
 * tries it again in its next runs before skipping it, so every listener of
 * the event may run more than once. Keep listeners idempotent.
 *
 * The reporter is $ticket['reporter']['id'], the host's own user id.
 */
class TicketUpdated
{
    /**
     * @param  string  $type  ticket.created, ticket.updated, ticket.replied or ticket.deleted
     * @param  array<string, mixed>  $ticket  the ticket as in the list (no replies; replies_count, last_reply_at)
     * @param  array<string, mixed>|null  $reply  ticket.replied only: {id, from, author, body, created_at, attachments}
     */
    public function __construct(
        public readonly string $type,
        public readonly array $ticket,
        public readonly ?array $reply = null,
    ) {}
}
