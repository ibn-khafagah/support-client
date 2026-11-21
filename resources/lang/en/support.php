<?php

return [

    'title' => 'Support',

    'nav' => [
        'tickets' => 'Tickets',
        'new' => 'New ticket',
        'back' => 'Back to :app',
        'main' => 'Support navigation',
    ],

    'index' => [
        'title' => 'My tickets',
        'all_title' => 'All company tickets',
        'scope_all' => 'All tickets',
        'scope_mine' => 'My tickets',
        'scope_label' => 'Whose tickets',
        'status_label' => 'Ticket status',
        'filter_all' => 'All',
        'filter_open' => 'Open',
        'filter_resolved' => 'Resolved',
        'filter_closed' => 'Closed',
        'number' => 'Number',
        'subject' => 'Subject',
        'category' => 'Category',
        'status' => 'Status',
        'reporter' => 'Opened by',
        'last_activity' => 'Last activity',
        'empty' => 'No tickets yet.',
        'empty_filtered' => 'No tickets match this filter.',
        'empty_hint' => 'Have a problem or a question? Open a ticket and the support team will answer here.',
        'page' => 'Page :current of :last',
        'prev' => 'Previous',
        'next' => 'Next',
        'pages' => 'Ticket pages',
        'new' => 'New',
        'new_hint' => 'Something new since you last opened it',
    ],

    'create' => [
        'title' => 'New ticket',
        'intro' => 'Describe the problem clearly and attach a screenshot if you can. The support team will answer here.',
        'subject_placeholder' => 'For example: error when saving an invoice',
        'choose_category' => 'Choose a category',
        'body_placeholder' => 'What were you doing? What happened? What did you expect?',
        'attachments_hint' => 'Up to :count files, :size MB each, :total MB in total. Allowed types: :types.',
        'auto_details' => 'Technical details that help the support team are sent with the ticket automatically: the app name and version, the page you were on, your browser and operating system, and the last error you saw, if any. No passwords or form data are sent.',
        'error_title' => 'This ticket will include the error reference',
        'error_note' => 'with the short error message and its time (:time), so the support team can find it quickly.',
        'submit' => 'Send ticket',
        'cancel' => 'Cancel',
        'fix_errors' => 'Check the marked fields and send again.',
        'no_categories' => 'No categories are available right now.',
        'offline_title' => 'The support service cannot be reached right now',
        'offline_note' => 'You can still send your ticket: it will be saved on this system and sent automatically as soon as the connection is back.',
        'covered_until' => 'Support was covered until :date.',
        'billing_only' => 'Until support is renewed you can open billing and subscription tickets only.',
        'no_billing' => 'New tickets cannot be opened until support is renewed. Contact your provider if you need help.',
    ],

    // the tickets saved while the support service could not be reached
    'outbox' => [
        'title' => 'Tickets not sent yet',
        'hint' => 'These tickets were saved while the support service could not be reached. Waiting tickets are sent automatically: there is no need to open them again.',
        'pending' => 'Waiting to be sent',
        'failed' => 'Not sent: :reason',
        'failed_hint' => 'A ticket that was not sent will not be tried again. Open a new ticket if you still need help.',
        'saved_at' => 'Saved :time',
    ],

    // a page that could not be loaded from the support service
    'unavailable' => [
        'title' => 'Support service unavailable',
        'retry' => 'Try again',
    ],

    'show' => [
        'back' => 'All tickets',
        'opened_by' => 'Opened by',
        'opened_at' => 'Opened',
        'updated_at' => 'Last update',
        'closed_at' => 'Closed',
        'conversation' => 'Conversation',
        'no_replies' => 'No messages yet.',
        'attachments' => 'Attachments',
        'download' => 'Download :name',
        'reply_title' => 'Add a reply',
        'reply_label' => 'Your reply',
        'reply_placeholder' => 'Write your reply here…',
        'reply_submit' => 'Send reply',
        'resolved_prompt' => 'The support team marked this problem as solved. Is it?',
        'resolved_close' => 'Yes, close the ticket',
        'resolved_hint' => 'Not solved yet? Write a reply below and the support team will pick it up again.',
        'closed_note' => 'This ticket is closed. If the problem comes back, write a reply to reopen it.',
        'close' => 'Close ticket',
        'close_confirm' => 'Close this ticket?',
        'reply_paused_title' => 'Replies on this ticket are paused',
        'reply_paused' => 'Support for this system is suspended. Renew the subscription to reply here, or open a billing ticket.',
        'reply_revoked' => 'Support is not available for this system.',
    ],

    'labels' => [
        'subject' => 'Subject',
        'category_id' => 'Category',
        'priority' => 'Priority',
        'body' => 'Description',
        'attachments' => 'Attachments',
    ],

    // field names inside validation messages ("The subject field is required.")
    'fields' => [
        'subject' => 'subject',
        'category_id' => 'category',
        'priority' => 'priority',
        'body' => 'description',
        'attachments' => 'attachments',
        'attachments.*' => 'attachment',
    ],

    'status' => [
        'open' => 'Open',
        'in_progress' => 'In progress',
        'waiting_client' => 'Waiting for you',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ],

    'priority' => [
        'urgent' => 'Urgent (system down)',
        'high' => 'High',
        'normal' => 'Normal',
        'low' => 'Low',
    ],

    'departments' => [
        'technical' => 'Technical support',
        'general' => 'General questions',
        'billing' => 'Billing and subscription',
    ],

    // <x-support-report-button /> on the error page
    'report' => [
        'ref' => 'Error reference:',
        'action' => 'Report this problem',
        'hint' => 'Opens a support ticket form with the error reference and the page where it happened.',
        'hint_no_ref' => 'Opens a support ticket form with the page where the error happened.',
    ],

    'staff_author' => 'Support team — :name',
    'staff_team' => 'Support team',

    // <x-support-badge /> in the host's navigation
    'badge' => [
        'label' => 'Tickets with something new: :count',
    ],

    'flash' => [
        'created' => 'Ticket :number is open. The support team will answer here.',
        'replied' => 'Your reply was sent.',
        'closed' => 'The ticket is closed.',
        'queued' => 'The support service could not be reached, so your ticket was saved. It will be sent automatically as soon as the connection is back.',
    ],

    'problems' => [
        'unavailable' => 'The support service is unavailable right now. Try again shortly.',
        'outbox_full' => 'The support service is unavailable right now, and you already have :count tickets waiting to be sent. Try again once the connection is back.',
        'suspended_title' => 'Support is suspended',
        'revoked_title' => 'Support is not available for this system',
        'renew' => 'Renew subscription',
        'rate_limited' => 'Too many requests in a short time. Wait a minute and try again.',
        'failed' => 'The request could not be completed. Try again, and contact your provider if it keeps happening.',
        'not_found' => 'Ticket not found.',
        'too_large' => 'The attachments are too large: up to 20 MB at a time.',
        'file_missing' => 'One of the attachments could not be read. Choose it again and send.',
        'not_sent' => 'Nothing was sent: check the text and the attachments, then try again.',
        'forbidden' => 'The support screens are not available for this account.',
    ],

    'units' => [
        'kb' => 'KB',
        'mb' => 'MB',
    ],

];
