<?php

namespace IbnKhafagah\SupportClient\Laravel\Http;

use Closure;
use IbnKhafagah\SupportClient\ApiException;
use IbnKhafagah\SupportClient\Laravel\ContextCollector;
use IbnKhafagah\SupportClient\Laravel\Contracts\ResolvesSupportUser;
use IbnKhafagah\SupportClient\Laravel\LastError;
use IbnKhafagah\SupportClient\Laravel\Outbox;
use IbnKhafagah\SupportClient\Laravel\ReporterData;
use IbnKhafagah\SupportClient\Laravel\StatusCache;
use IbnKhafagah\SupportClient\Laravel\Unread;
use IbnKhafagah\SupportClient\SupportClient;
use IbnKhafagah\SupportClient\Transport\TransportException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The support screens: a server-side proxy to the CRM ticket API, so the
 * key stays on this server. Every action answers JSON when the request
 * wants JSON (for hosts with their own front end), otherwise a view.
 *
 * Ownership: the CRM shows every ticket of this system to the key, so the
 * package decides who sees what. A user who does not pass the admin gate
 * (config support-client.admin_gate) lists only tickets whose reporter id
 * is their own, and gets a 404 for any other ticket, exactly as for a
 * number that does not exist.
 *
 * Failures never end in a 500: a refusal from the CRM (ApiException)
 * becomes form errors or a message (with the renew link when support is
 * suspended), and an unreachable CRM (TransportException: no answer, a 5xx
 * or a body that is not JSON) the "service unavailable" page. A new ticket
 * is then saved in the outbox instead, and `support:flush` sends it when
 * the CRM is back; the user's ticket list shows it meanwhile.
 *
 * The support status (StatusCache) shapes the screens as the CRM's rules
 * would (design §5.2): while suspended only billing tickets can be opened
 * and the reply box follows the reply rule; while revoked neither is
 * offered. The CRM still decides every call.
 */
class SupportController extends Controller
{
    public const STATUSES = ['open', 'in_progress', 'waiting_client', 'resolved', 'closed'];

    /** The statuses the CRM counts as open (TicketStatus::isOpen()). */
    public const OPEN_STATUSES = ['open', 'in_progress', 'waiting_client'];

    public const SUPPORT_SUSPENDED = 'suspended';

    public const SUPPORT_REVOKED = 'revoked';

    /** The only department that can be used while support is suspended. */
    public const BILLING = 'billing';

    public const PRIORITIES = ['urgent', 'high', 'normal', 'low'];

    /** The API's limits for attachments (design §5.4). */
    public const EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'txt', 'log', 'zip', 'docx', 'xlsx'];

    public const MAX_FILES = 5;

    public const MAX_FILE_KB = 10 * 1024;

    public const MAX_TOTAL_BYTES = 20 * 1024 * 1024;

    /** Tickets one user may have waiting in the outbox while the CRM is unreachable. */
    public const OUTBOX_PER_REPORTER = 10;

    /** Session keys of the one-time messages shown by the layout. */
    public const FLASH_STATUS = 'support_client_status';

    public const FLASH_PROBLEM = 'support_client_problem';

    /**
     * GET / — the user's tickets; for a support admin every ticket of the
     * system (?scope=mine narrows it to their own). ?status= filters
     * (open = any open status), ?page= pages.
     *
     * Above the list: the user's own tickets still in the outbox, waiting
     * to be sent or refused by the CRM (JSON: `outbox`). Only their own,
     * for an admin too.
     */
    public function index(Request $request): Response
    {
        [$reporter, $admin] = $this->actor($request);

        $status = in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : null;
        $scope = $admin && $request->query('scope') !== 'mine' ? 'all' : 'mine';
        $page = max(1, min(100000, (int) $request->query('page', '1')));
        $filters = array_filter(['status' => $status, 'scope' => $admin && $scope === 'mine' ? 'mine' : null]);
        $outbox = $this->outboxEntries($reporter);

        return $this->attempt($request, $this->outageOn404(function () use ($request, $reporter, $admin, $status, $scope, $page, $filters, $outbox) {
            $list = $this->client()->tickets([
                // a non-admin only ever asks for their own tickets
                'reporter' => $scope === 'mine' ? $reporter->id : null,
                'status' => $status,
                'page' => $page > 1 ? $page : null,
            ]);

            $tickets = array_values(array_filter(
                is_array($list['data'] ?? null) ? $list['data'] : [],
                fn ($ticket) => is_array($ticket) && ($admin || $this->owns($ticket, $reporter)),
            ));
            $meta = $this->meta(is_array($list['meta'] ?? null) ? $list['meta'] : [], count($tickets));
            // the CRM's own paging links point at the CRM: rebuilt on this app's route
            $links = [
                'prev' => $meta['current_page'] > 1 ? route('support-client.index', $filters + ['page' => $meta['current_page'] - 1]) : null,
                'next' => $meta['current_page'] < $meta['last_page'] ? route('support-client.index', $filters + ['page' => $meta['current_page'] + 1]) : null,
            ];
            // the user's own tickets with something new since they last opened them
            $unread = array_flip($this->unreadNumbers($reporter));

            if ($request->wantsJson()) {
                $data = array_map(fn (array $ticket) => $ticket + ['unread' => isset($unread[(string) ($ticket['number'] ?? '')])], $tickets);

                return new JsonResponse(['data' => $data, 'links' => $links, 'meta' => $meta, 'outbox' => $outbox]);
            }

            return $this->view('index', compact('tickets', 'links', 'meta', 'status', 'scope', 'admin', 'filters', 'unread', 'outbox'));
        }));
    }

    /**
     * GET /create — the new-ticket form. JSON: the support status, the
     * categories that can be used now (named in the app locale), the
     * priorities, the error a new ticket will carry ({ref, at} or null) and
     * `offline` (true when shown from the cache while the CRM is down).
     *
     * The status read here is kept (StatusCache, with the categories), so
     * while the CRM can't be reached the form is still shown from it, with
     * a notice that the ticket will be saved and sent automatically. With
     * nothing kept yet, the "service unavailable" page.
     *
     * Suspended: the CRM's message, covered_until and the renew link, and
     * the billing categories only (none: no form). Revoked: the message, no
     * form.
     *
     * From the "Report this problem" button (?from=<page>&error=<ref>): the
     * form keeps the page in a hidden field and, when the ref is one of the
     * user's kept errors, pre-selects the "System error" category (while
     * suspended only a billing category could be, so nothing is).
     */
    public function create(Request $request): Response
    {
        $this->actor($request);

        return $this->attempt($request, function () use ($request) {
            $offline = false;
            try {
                $status = $this->outageOn404(fn () => $this->client()->status())();
                $system = $status['system'] ?? null;
                $support = is_array($status['support'] ?? null) ? $status['support'] : [];
                $categories = $this->validCategories($status['categories'] ?? null);
                $this->rememberStatus($support, $categories);
            } catch (TransportException $e) {
                $support = $this->cachedStatus();
                $categories = $this->cachedCategories();
                if ($support === null || $categories === null) {
                    throw $e;
                }

                Log::warning('support-client: the support API is unreachable; the create form is shown from the last known status and a new ticket goes to the outbox.', ['error' => $e->getMessage()]);
                $offline = true;
                $system = null;
            }

            if (array_key_exists('renew_url', $support)) {
                $support['renew_url'] = $this->safeUrl($support['renew_url']);
            }
            $state = (string) ($support['status'] ?? '');
            $categories = $this->usableCategories($categories, $state);
            $canCreate = match ($state) {
                self::SUPPORT_REVOKED => false,
                self::SUPPORT_SUSPENDED => $categories !== [],
                default => true,
            };

            // after a failed submit the hidden fields come back as old input
            $from = $this->text($request->old('from', $request->query('from')));
            $reported = LastError::find($this->text($request->old('error', $request->query('error'))));
            // what a new ticket will carry (ContextCollector: the reported error, else the newest)
            $lastError = $reported ?? LastError::get();

            if ($request->wantsJson()) {
                return new JsonResponse([
                    'system' => $system,
                    'support' => $support,
                    'categories' => $categories,
                    'priorities' => self::PRIORITIES,
                    'last_error' => $lastError !== null ? ['ref' => $lastError['ref'], 'at' => $lastError['at']] : null,
                    'offline' => $offline,
                ]);
            }

            $data = [
                'support' => $support,
                'state' => $state,
                'offline' => $offline,
                'canCreate' => $canCreate,
                'categories' => $categories,
                'priorities' => self::PRIORITIES,
                'limits' => $this->limits(),
                'from' => $this->collector()->pageUrl($request, $from),
                'lastError' => $lastError,
                'errorRef' => $reported['ref'] ?? null,
                'preselectedCategory' => $reported !== null ? $this->errorCategory($categories) : null,
            ];

            // a refusal flashed by a failed submit says what the banner already says
            $flashed = $request->hasSession() ? $request->session()->get(self::FLASH_PROBLEM) : null;
            if (in_array($state, [self::SUPPORT_SUSPENDED, self::SUPPORT_REVOKED], true) && is_array($flashed)
                && in_array($flashed['code'] ?? null, [ApiException::SUPPORT_SUSPENDED, ApiException::SUPPORT_REVOKED], true)) {
                $data['problem'] = false;
            }

            return $this->view('create', $data);
        });
    }

    /**
     * POST / — opens a ticket as the logged-in user. 201 with the ticket in
     * JSON; otherwise to the ticket page.
     *
     * When the CRM can't be reached (or SUPPORT_API_URL is unusable) the
     * ticket is saved in the outbox, with its files, under its
     * external_reference: 202 {queued: true, reference} in JSON; otherwise
     * to the ticket list with "saved, it will be sent automatically".
     */
    public function store(Request $request): Response
    {
        [$reporter] = $this->actor($request);
        $back = fn () => redirect()->route('support-client.create')->withInput();

        $validator = $this->validator($request, [
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'category_id' => ['required', 'integer', 'min:1'],
            'priority' => ['required', 'string', 'in:'.implode(',', self::PRIORITIES)],
        ]);
        if ($validator->fails()) {
            return $this->invalid($request, $validator, $back);
        }
        $data = $validator->validated();

        return $this->attempt($request, function () use ($request, $reporter, $data, $back) {
            $payload = [
                'subject' => $data['subject'],
                'body' => $data['body'],
                'category_id' => (int) $data['category_id'],
                'priority' => $data['priority'],
                'reporter' => $reporter->toArray(),
                // one per submission: sending it again with the same value never opens a second ticket
                'external_reference' => (string) Str::uuid(),
            ];
            $context = $this->collector()->collect($request, $reporter, $this->text($request->input('from')));
            if ($context !== []) {
                $payload['context'] = $context;
            }

            $files = $this->uploads($request);
            try {
                $created = $this->client()->createTicket($payload, $files);
            } catch (InvalidArgumentException $e) {
                return $this->notSent($request, $e, $back);
            } catch (TransportException $e) {
                return $this->queue($request, $payload, $files, $e, $back);
            }
            $ticket = $this->present($created);

            // the error went with this ticket: the next one does not carry it again
            if (isset($context['error_ref'])) {
                LastError::forget();
            }

            if ($request->wantsJson()) {
                return new JsonResponse($ticket, 201);
            }

            return redirect()->route('support-client.show', $ticket['number'])
                ->with(self::FLASH_STATUS, __('support-client::support.flash.created', ['number' => $ticket['number']]));
        }, $back);
    }

    /**
     * GET /unread — {count}: how many of the user's tickets have something
     * new (a reply or a change by the support team) since they last opened
     * them. Read from this app's cache: the CRM is not asked.
     */
    public function unread(Request $request): JsonResponse
    {
        [$reporter] = $this->actor($request);

        return new JsonResponse(['count' => count($this->unreadNumbers($reporter))]);
    }

    /**
     * GET /{number} — the ticket with its public replies. Another user's
     * ticket is a 404 for a non-admin. Opening their own ticket clears its
     * unread mark; a 404 drops the user's mark on that number (the ticket is
     * gone, or was never theirs), since opening it could never clear it.
     *
     * The reply box follows the CRM's reply rule (replyAllowed()); where it
     * would be refused, the renewal message takes its place. JSON adds
     * `can_reply`. The CRM still decides when a reply is sent.
     */
    public function show(Request $request, string $number): Response
    {
        [$reporter, $admin] = $this->actor($request);

        return $this->attempt($request, function () use ($request, $number, $reporter, $admin) {
            try {
                $visible = $this->visibleTicket($number, $reporter, $admin);
            } catch (ApiException $e) {
                if ($e->getCode() === ApiException::NOT_FOUND) {
                    $this->forgetMark($reporter, $number);
                }

                throw $e;
            }
            if ($this->owns($visible, $reporter)) {
                $this->markSeen($reporter, $visible);
            }
            $ticket = $this->present($visible);
            $replyBlock = $this->replyBlock($visible);

            if ($request->wantsJson()) {
                return new JsonResponse($ticket + ['can_reply' => $replyBlock === null]);
            }

            return $this->view('show', ['ticket' => $ticket, 'admin' => $admin, 'limits' => $this->limits(), 'replyBlock' => $replyBlock]);
        });
    }

    /**
     * POST /{number}/replies — a reply as the logged-in user. 201 with the
     * ticket in JSON; otherwise back to the ticket. A support admin's reply
     * on another user's ticket marks it unread for that user (markForReporter()).
     */
    public function reply(Request $request, string $number): Response
    {
        [$reporter, $admin] = $this->actor($request);
        $back = fn () => redirect()->route('support-client.show', $number)->withInput();

        $validator = $this->validator($request, ['body' => ['required', 'string', 'max:20000']]);
        if ($validator->fails()) {
            return $this->invalid($request, $validator, $back);
        }
        $body = (string) $validator->validated()['body'];

        return $this->attempt($request, function () use ($request, $number, $reporter, $admin, $body, $back) {
            if (! $admin) {
                $this->visibleTicket($number, $reporter, false);
            }

            $client = $this->client();
            try {
                $sent = $client->reply($number, ['body' => $body, 'reporter' => $reporter->toArray()], $this->uploads($request));
            } catch (InvalidArgumentException $e) {
                return $this->notSent($request, $e, $back);
            }
            if ($admin) {
                $this->markForReporter($reporter, $sent, true);
            }
            $ticket = $this->present($sent);

            if ($request->wantsJson()) {
                return new JsonResponse($ticket, 201);
            }

            return redirect()->route('support-client.show', $ticket['number'])
                ->with(self::FLASH_STATUS, __('support-client::support.flash.replied'));
        }, $back);
    }

    /**
     * POST /{number}/close — the user confirms it is solved. A support
     * admin closing another user's ticket marks it unread for that user.
     */
    public function close(Request $request, string $number): Response
    {
        [$reporter, $admin] = $this->actor($request);
        $back = fn () => redirect()->route('support-client.show', $number);

        return $this->attempt($request, function () use ($request, $number, $reporter, $admin) {
            if (! $admin) {
                $this->visibleTicket($number, $reporter, false);
            }

            $closed = $this->client()->close($number);
            if ($admin) {
                $this->markForReporter($reporter, $closed, false);
            }
            $ticket = $this->present($closed);

            if ($request->wantsJson()) {
                return new JsonResponse($ticket);
            }

            return redirect()->route('support-client.show', $ticket['number'])
                ->with(self::FLASH_STATUS, __('support-client::support.flash.closed'));
        }, $back);
    }

    /**
     * GET /{number}/attachments/{id} — streams a file of the ticket from the
     * CRM, always as a download. Only a file the ticket lists, on a ticket
     * the user may see; anything else is a 404.
     */
    public function attachment(Request $request, string $number, string $id): Response
    {
        [$reporter, $admin] = $this->actor($request);

        return $this->attempt($request, function () use ($number, $id, $reporter, $admin) {
            $ticket = $this->visibleTicket($number, $reporter, $admin);
            $file = $this->findAttachment($ticket, (int) $id)
                ?? throw new ApiException(ApiException::NOT_FOUND, 'Attachment not found.', 404);

            $download = $this->client()->attachment((string) $ticket['number'], (int) $id);

            return $this->download($download, (string) ($file['name'] ?? ''), (int) $id);
        });
    }

    /**
     * The logged-in user as a reporter, and whether they pass the admin gate.
     *
     * @return array{0: ReporterData, 1: bool}
     */
    protected function actor(Request $request): array
    {
        $user = $request->user();
        if ($user === null) {
            $this->forbidden($request);
        }

        $reporter = app(ResolvesSupportUser::class)->resolve($user);
        // without an id every ticket would match; an id longer than the CRM keeps would be
        // cut there, so two users whose ids start alike would share tickets: refused
        if (! $reporter->hasUsableId()) {
            Log::warning('support-client: the user id from the resolver is empty or longer than '.ReporterData::MAX_ID.' characters; the support screens refuse it.');

            $this->forbidden($request);
        }

        $gate = (string) config('support-client.admin_gate', '');
        $admin = $gate !== '' && app(Gate::class)->forUser($user)->allows($gate);

        return [$reporter, $admin];
    }

    /**
     * 403, in the package's {error, message} format for JSON.
     */
    protected function forbidden(Request $request): never
    {
        if ($request->wantsJson()) {
            throw new HttpResponseException($this->jsonError('forbidden', __('support-client::support.problems.forbidden'), 403));
        }

        abort(403);
    }

    /**
     * For a read whose 404 cannot mean "no such ticket" (the list, the
     * status): the CRM's URL is wrong or the API is missing there, so the
     * user sees "service unavailable" and the developer gets a log line.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return Closure(): T
     */
    protected function outageOn404(Closure $call): Closure
    {
        return function () use ($call) {
            try {
                return $call();
            } catch (ApiException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }

                Log::warning('support-client: the support API answered 404 for a list or the status. Check SUPPORT_API_URL.', ['error' => $e->getCode()]);

                throw new TransportException('The support API was not found at the configured URL (404).', 0, $e);
            }
        };
    }

    /**
     * Nothing was sent: a file that vanished after validation, or text that
     * cannot be encoded (the client's InvalidArgumentException).
     */
    protected function notSent(Request $request, InvalidArgumentException $e, Closure $back): Response
    {
        Log::warning('support-client: the request was not sent to the support API.', ['error' => $e->getMessage()]);

        return $this->fieldErrors($request, ApiException::VALIDATION_FAILED, 422, ['form' => [__('support-client::support.problems.not_sent')]], $back);
    }

    /**
     * The CRM can't be reached: the ticket is saved in the outbox under its
     * external_reference, for `support:flush`. If even that fails (a full
     * disk), the form comes back with its input and "unavailable".
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array{path: string, name: string, type: string}>  $files
     */
    protected function queue(Request $request, array $payload, array $files, TransportException $e, Closure $back): Response
    {
        try {
            // one user can't fill the disk with saved tickets (each up to 20 MB of files) during an outage
            $waiting = array_filter(
                $this->outbox()->forReporter((string) ($payload['reporter']['id'] ?? '')),
                fn (array $entry) => $entry['state'] === 'pending',
            );
            if (count($waiting) >= self::OUTBOX_PER_REPORTER) {
                Log::warning('support-client: the support API is unreachable and this user already has the most tickets waiting in the outbox.', ['error' => $e->getMessage()]);

                return $this->problem($request, ['message' => __('support-client::support.problems.outbox_full', ['count' => self::OUTBOX_PER_REPORTER])] + $this->unavailable(), 503, $back);
            }

            $reference = $this->outbox()->put($payload, $files, (string) $payload['external_reference']);
        } catch (Throwable $saveError) {
            report($saveError);
            Log::warning('support-client: the support API is unreachable and the ticket could not be saved in the outbox.', ['error' => $e->getMessage()]);

            return $this->problem($request, $this->unavailable(), 503, $back);
        }

        Log::warning('support-client: the support API is unreachable; the ticket was saved in the outbox for support:flush.', ['reference' => $reference, 'error' => $e->getMessage()]);

        if ($request->wantsJson()) {
            return new JsonResponse(['queued' => true, 'reference' => $reference], 202);
        }

        return redirect()->route('support-client.index')
            ->with(self::FLASH_STATUS, __('support-client::support.flash.queued'));
    }

    /**
     * The user's own tickets in the outbox, newest first, as the list shows
     * them. Never fails the screen: an outbox that can't be read shows none.
     *
     * @return list<array{reference: string, subject: string, created_at: string, state: string, reason: ?string}>
     */
    protected function outboxEntries(?ReporterData $reporter): array
    {
        if ($reporter === null) {
            return [];
        }

        try {
            return array_map(fn (array $entry) => [
                'reference' => $entry['uuid'],
                'subject' => $entry['subject'],
                'created_at' => $entry['created_at'],
                'state' => $entry['state'],
                'reason' => $entry['reason'],
            ], $this->outbox()->forReporter($reporter->id));
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** The logged-in user as a reporter, or null (for pages that must render whatever happens). */
    protected function currentReporter(Request $request): ?ReporterData
    {
        try {
            $user = $request->user();
            $reporter = $user !== null ? app(ResolvesSupportUser::class)->resolve($user) : null;

            return $reporter !== null && $reporter->hasUsableId() ? $reporter : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Why the reply box is hidden on this ticket ({title, message,
     * renew_url}), or null to show it. With no status known and none to be
     * read, the box is shown: the CRM decides when the reply is sent.
     *
     * @param  array<string, mixed>  $ticket
     * @return array{title: string, message: string, renew_url: ?string}|null
     */
    protected function replyBlock(array $ticket): ?array
    {
        $support = $this->knownStatus();
        if ($support === null || $this->replyAllowed($ticket, $support)) {
            return null;
        }

        $revoked = ($support['status'] ?? null) === self::SUPPORT_REVOKED;
        $message = is_string($support['message'] ?? null) && trim($support['message']) !== '' ? $support['message'] : null;

        return [
            'title' => __($revoked ? 'support-client::support.problems.revoked_title' : 'support-client::support.show.reply_paused_title'),
            'message' => $message ?? __($revoked ? 'support-client::support.show.reply_revoked' : 'support-client::support.show.reply_paused'),
            'renew_url' => $this->safeUrl($support['renew_url'] ?? null),
        ];
    }

    /**
     * The CRM's reply rule (SupportStatusResult::allowsReply(), design
     * §5.2): any ticket while active; none while revoked; while suspended
     * an open ticket, a billing ticket, or one resolved less than
     * reply_window_days ago. A status this version does not know, or a
     * ticket without a category (the CRM then looks at a department the API
     * does not show), leaves it to the CRM.
     *
     * @param  array<string, mixed>  $ticket
     * @param  array<string, mixed>  $support
     */
    protected function replyAllowed(array $ticket, array $support): bool
    {
        $state = $support['status'] ?? null;
        if ($state === self::SUPPORT_REVOKED) {
            return false;
        }
        if ($state !== self::SUPPORT_SUSPENDED) {
            return true;
        }

        $status = $ticket['status'] ?? null;
        if (in_array($status, self::OPEN_STATUSES, true)) {
            return true;
        }
        if (! is_array($ticket['category'] ?? null)) {
            return true;
        }
        if (($ticket['category']['department'] ?? null) === self::BILLING) {
            return true;
        }

        if ($status === 'resolved' && is_string($ticket['resolved_at'] ?? null)) {
            $days = is_int($support['reply_window_days'] ?? null) ? $support['reply_window_days'] : StatusCache::DEFAULT_REPLY_WINDOW_DAYS;
            try {
                return Carbon::parse($ticket['resolved_at'])->addDays($days)->isFuture();
            } catch (Throwable) {
                return true;
            }
        }

        return false;
    }

    /**
     * The support status as last heard; when none was heard yet, read once
     * from the CRM and kept. Null when it can't be had.
     *
     * @return array<string, mixed>|null
     */
    protected function knownStatus(): ?array
    {
        $support = $this->cachedStatus();
        if ($support !== null) {
            return $support;
        }

        try {
            $status = $this->client()->status();
        } catch (ApiException|TransportException) {
            return null;
        }

        $this->rememberStatus(
            is_array($status['support'] ?? null) ? $status['support'] : [],
            $this->validCategories($status['categories'] ?? null),
        );

        return $this->cachedStatus();
    }

    /**
     * The status as last heard (StatusCache), or null. It may come from a
     * notification, kept as the CRM sent it, so its renew_url goes through
     * safeUrl() here, once for every screen and JSON answer that shows it.
     *
     * @return array<string, mixed>|null
     */
    protected function cachedStatus(): ?array
    {
        try {
            $support = $this->statusCache()->get();
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($support !== null) {
            $support['renew_url'] = $this->safeUrl($support['renew_url'] ?? null);
        }

        return $support;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    protected function cachedCategories(): ?array
    {
        try {
            return $this->statusCache()->categories(app()->getLocale());
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Keeps a status read live, and its categories, for the screens that
     * need them while the CRM can't be reached.
     *
     * @param  array<string, mixed>  $support
     * @param  list<array<string, mixed>>  $categories
     */
    protected function rememberStatus(array $support, array $categories): void
    {
        try {
            $this->statusCache()->remember($support);
            $this->statusCache()->rememberCategories($categories, app()->getLocale());
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function validCategories(mixed $categories): array
    {
        return array_values(array_filter(
            is_array($categories) ? $categories : [],
            fn ($category) => is_array($category) && isset($category['id'], $category['name']),
        ));
    }

    /**
     * The categories a new ticket can use now: all while active, billing
     * only while suspended, none while revoked.
     *
     * @param  list<array<string, mixed>>  $categories
     * @return list<array<string, mixed>>
     */
    protected function usableCategories(array $categories, string $state): array
    {
        return match ($state) {
            self::SUPPORT_REVOKED => [],
            self::SUPPORT_SUSPENDED => array_values(array_filter($categories, fn (array $category) => ($category['department'] ?? null) === self::BILLING)),
            default => $categories,
        };
    }

    protected function outbox(): Outbox
    {
        return app(Outbox::class);
    }

    protected function statusCache(): StatusCache
    {
        return app(StatusCache::class);
    }

    /**
     * The numbers of the user's tickets with something new. The screens
     * never fail because of it: a cache that cannot be read means no marks.
     *
     * @return list<string>
     */
    protected function unreadNumbers(ReporterData $reporter): array
    {
        try {
            return app(Unread::class)->numbers($reporter->id);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /**
     * The user has seen the ticket as it is now: its unread mark goes, and
     * a later event about an older change does not bring it back.
     *
     * @param  array<string, mixed>  $ticket
     */
    protected function markSeen(ReporterData $reporter, array $ticket): void
    {
        $dates = [$ticket['updated_at'] ?? null, $ticket['last_reply_at'] ?? null];
        $lastReply = null;
        foreach (is_array($ticket['replies'] ?? null) ? $ticket['replies'] : [] as $reply) {
            if (is_array($reply)) {
                $dates[] = $reply['created_at'] ?? null;
                $lastReply = is_int($reply['id'] ?? null) ? max((int) $lastReply, $reply['id']) : $lastReply;
            }
        }

        try {
            app(Unread::class)->clear($reporter->id, (string) ($ticket['number'] ?? ''), Unread::latest($dates), $lastReply);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The ticket can't be opened (the CRM has no such ticket, or it is not
     * this user's): their mark on that number could never be cleared by
     * opening it, so it goes. Only their own mark, and no tombstone
     * (Unread::deleted()): a 404 here may just mean another user's ticket.
     */
    protected function forgetMark(ReporterData $reporter, string $number): void
    {
        try {
            app(Unread::class)->forget($reporter->id, $number);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A support admin replied to or closed a ticket. The CRM records that as
     * this app's own call (origin `api`), and SupportEventHandler marks
     * nothing for those, so when the ticket belongs to another user their
     * mark is set here, by the handler's rules: the newest of the ticket's
     * times, and for a reply also the reply's own time and id (the newest
     * reply on the returned ticket: the one just sent).
     *
     * @param  array<string, mixed>  $ticket  the full ticket the CRM returned
     */
    protected function markForReporter(ReporterData $actor, array $ticket, bool $replied): void
    {
        $owner = is_array($ticket['reporter'] ?? null) ? ($ticket['reporter']['id'] ?? null) : null;
        $number = is_string($ticket['number'] ?? null) ? $ticket['number'] : '';
        if (! (is_string($owner) || is_int($owner)) || (string) $owner === '' || (string) $owner === $actor->id || $number === '') {
            return;
        }

        $reply = null;
        if ($replied) {
            foreach (is_array($ticket['replies'] ?? null) ? $ticket['replies'] : [] as $candidate) {
                if (is_array($candidate) && is_int($candidate['id'] ?? null) && ($reply === null || $candidate['id'] > $reply['id'])) {
                    $reply = $candidate;
                }
            }
        }

        $at = Unread::latest([$ticket['updated_at'] ?? null, $ticket['last_reply_at'] ?? null, $reply['created_at'] ?? null]);
        if ($at === null) {
            return;
        }

        try {
            app(Unread::class)->mark((string) $owner, $number, $at, $reply['id'] ?? null);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** The automatic details of a new ticket (the API's `context`); a host may bind its own subclass. */
    protected function collector(): ContextCollector
    {
        return app(ContextCollector::class);
    }

    /**
     * The category a reported error goes to: the one named "خطأ في النظام" /
     * "System error" (by name, never a fixed id), else the first technical one.
     *
     * @param  list<array<string, mixed>>  $categories
     */
    protected function errorCategory(array $categories): ?int
    {
        $names = ['خطأ في النظام', 'system error'];

        foreach ($categories as $category) {
            if (in_array(mb_strtolower(trim((string) $category['name'])), $names, true)) {
                return (int) $category['id'];
            }
        }

        foreach ($categories as $category) {
            if (($category['department'] ?? null) === 'technical') {
                return (int) $category['id'];
            }
        }

        return null;
    }

    /** A request value that should be one line of text: anything else (an array, a number) is ignored. */
    protected function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Runs one call to the CRM and turns its failures into a response.
     * $back (the form to return to) marks a write: errors and messages go
     * back there with the input; reads show the message on the page.
     * (A client call that sent nothing, InvalidArgumentException, is caught
     * around that call itself: see notSent().)
     *
     * @param  Closure(): Response  $call
     * @param  (Closure(): RedirectResponse)|null  $back
     */
    protected function attempt(Request $request, Closure $call, ?Closure $back = null): Response
    {
        try {
            return $call();
        } catch (ApiException $e) {
            return $this->refused($request, $e, $back);
        } catch (TransportException $e) {
            Log::warning('support-client: the support API is unreachable.', ['error' => $e->getMessage()]);

            return $this->problem($request, $this->unavailable(), 503, $back);
        }
    }

    /**
     * A refusal from the CRM as the user should see it.
     */
    protected function refused(Request $request, ApiException $e, ?Closure $back): Response
    {
        $code = $e->getCode();

        switch ($code) {
            case ApiException::NOT_FOUND:
                return $this->notFound($request);

            case ApiException::VALIDATION_FAILED:
            case ApiException::TOO_LARGE:
                $errors = $e->errors !== [] ? $e->errors : ($code === ApiException::TOO_LARGE
                    ? ['attachments' => [__('support-client::support.problems.too_large')]]
                    : ['form' => [$e->getMessage()]]);

                return $this->fieldErrors($request, $code, $e->status, $errors, $back);

            case ApiException::UNAUTHORIZED:
                Log::warning('support-client: the CRM refused the API key (401). Check SUPPORT_API_KEY.');

                return $this->problem($request, $this->unavailable(), 503, $back);

            case ApiException::SUPPORT_SUSPENDED:
                return $this->problem($request, [
                    'code' => $code,
                    'title' => __('support-client::support.problems.suspended_title'),
                    'message' => $e->getMessage(),
                    'renew_url' => $this->safeUrl($e->body['renew_url'] ?? null),
                ], 403, $back);

            case ApiException::SUPPORT_REVOKED:
                return $this->problem($request, [
                    'code' => $code,
                    'title' => __('support-client::support.problems.revoked_title'),
                    'message' => $e->getMessage(),
                ], 403, $back);

            case ApiException::RATE_LIMITED:
                return $this->problem($request, [
                    'code' => $code,
                    'message' => __('support-client::support.problems.rate_limited'),
                ], 429, $back);

            default:
                Log::warning('support-client: the support API refused the request.', ['error' => $code, 'status' => $e->status]);

                return $this->problem($request, [
                    'code' => 'support_error',
                    'message' => __('support-client::support.problems.failed'),
                ], 502, $back);
        }
    }

    /**
     * A message instead of the result: JSON {error, message, renew_url?};
     * a write goes back to its form with the message; a read shows the
     * "unavailable" page with the message (and the user's tickets still in
     * the outbox), with the HTTP status of the problem (503 when the CRM
     * can't be reached).
     *
     * @param  array{code: string, title?: string|null, message: string, renew_url?: string|null}  $problem
     */
    protected function problem(Request $request, array $problem, int $status, ?Closure $back): Response
    {
        $problem += ['title' => null, 'renew_url' => null];

        if ($request->wantsJson()) {
            return $this->jsonError($problem['code'], $problem['message'], $status, array_filter(['renew_url' => $problem['renew_url']]));
        }

        if ($back !== null) {
            return $back()->with(self::FLASH_PROBLEM, $problem);
        }

        // a failed write came back here with its input (a reply while the CRM is down):
        // kept for the next request, so the text survives until the page works again
        if ($request->hasSession() && $request->session()->has('_old_input')) {
            $request->session()->reflash();
        }

        return $this->view('unavailable', [
            // shown by the page itself, in place of the layout's message box
            'problem' => false,
            'failure' => $problem,
            'unread' => [],
            'outbox' => $this->outboxEntries($this->currentReporter($request)),
        ], $status);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    protected function fieldErrors(Request $request, string $code, int $status, array $errors, ?Closure $back): Response
    {
        $first = (string) (array_values($errors)[0][0] ?? '');

        if ($request->wantsJson()) {
            return $this->jsonError($code, $first, $status, ['errors' => $errors]);
        }

        if ($back !== null) {
            return $back()->withErrors($errors);
        }

        return $this->problem($request, ['code' => $code, 'message' => $first], $status, null);
    }

    protected function invalid(Request $request, ValidatorContract $validator, Closure $back): Response
    {
        return $this->fieldErrors($request, ApiException::VALIDATION_FAILED, 422, $validator->errors()->toArray(), $back);
    }

    /** The same answer for a ticket that does not exist and for someone else's. */
    protected function notFound(Request $request): Response
    {
        if ($request->wantsJson()) {
            return $this->jsonError(ApiException::NOT_FOUND, __('support-client::support.problems.not_found'), 404);
        }

        abort(404);
    }

    /**
     * @return array{code: string, message: string}
     */
    protected function unavailable(): array
    {
        return [
            'code' => 'support_unavailable',
            'message' => __('support-client::support.problems.unavailable'),
        ];
    }

    /**
     * The ticket, if this user may see it: their own, or any for an admin.
     * Otherwise the same not_found as a number that does not exist.
     *
     * @return array<string, mixed>
     */
    protected function visibleTicket(string $number, ReporterData $reporter, bool $admin): array
    {
        $ticket = $this->client()->ticket($number);

        if (! $admin && ! $this->owns($ticket, $reporter)) {
            throw new ApiException(ApiException::NOT_FOUND, 'Ticket not found.', 404);
        }

        return $ticket;
    }

    /**
     * @param  array<string, mixed>  $ticket
     */
    protected function owns(array $ticket, ReporterData $reporter): bool
    {
        $id = $ticket['reporter']['id'] ?? null;

        return (is_string($id) || is_int($id)) && (string) $id === $reporter->id;
    }

    /**
     * The ticket as the API sent it, each attachment with the `url` of this
     * app's download route (never the CRM's).
     *
     * @param  array<string, mixed>  $ticket
     * @return array<string, mixed>
     */
    protected function present(array $ticket): array
    {
        $number = (string) ($ticket['number'] ?? '');

        foreach (is_array($ticket['replies'] ?? null) ? $ticket['replies'] : [] as $i => $reply) {
            foreach (is_array($reply['attachments'] ?? null) ? $reply['attachments'] : [] as $j => $file) {
                $ticket['replies'][$i]['attachments'][$j]['url'] = route('support-client.attachment', ['number' => $number, 'id' => (int) ($file['id'] ?? 0)]);
            }
        }

        return $ticket;
    }

    /**
     * @param  array<string, mixed>  $ticket
     * @return array<string, mixed>|null
     */
    protected function findAttachment(array $ticket, int $id): ?array
    {
        foreach (is_array($ticket['replies'] ?? null) ? $ticket['replies'] : [] as $reply) {
            foreach (is_array($reply['attachments'] ?? null) ? $reply['attachments'] : [] as $file) {
                if (is_array($file) && (int) ($file['id'] ?? 0) === $id) {
                    return $file;
                }
            }
        }

        return null;
    }

    /**
     * The CRM's file passed through as a download: always "attachment" and
     * nosniff, so a file is never shown inline on this app's origin.
     *
     * @param  array{status: int, headers: array<string, string>, stream: resource}  $download
     */
    protected function download(array $download, string $name, int $id): StreamedResponse
    {
        $stream = $download['stream'];
        $headers = $download['headers'];

        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', $name));
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $fallback = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', Str::ascii($name)), '._');
        if (! preg_match('/[A-Za-z0-9]/', $fallback)) {
            $fallback = 'attachment-'.$id.($extension !== '' && ctype_alnum($extension) ? '.'.$extension : '');
        }
        if ($name === '') {
            $name = $fallback;
        }

        $response = new StreamedResponse(function () use ($stream) {
            try {
                fpassthru($stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }, 200, [
            'Content-Type' => $headers['content-type'] ?? 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $fallback),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);

        if (isset($headers['content-length']) && ctype_digit($headers['content-length'])) {
            $response->headers->set('Content-Length', $headers['content-length']);
        }

        return $response;
    }

    /**
     * A validator with the attachment rules (count, size, type, total) and
     * the field names of the package's translations.
     *
     * @param  array<string, mixed>  $rules
     */
    protected function validator(Request $request, array $rules): ValidatorContract
    {
        $names = __('support-client::support.fields');

        $validator = Validator::make($request->all(), $rules + [
            'attachments' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'attachments.*' => ['file', 'max:'.self::MAX_FILE_KB, 'extensions:'.implode(',', self::EXTENSIONS)],
        ], [], is_array($names) ? $names : []);

        $validator->after(function (ValidatorContract $validator) use ($request) {
            $total = 0;
            foreach ($this->files($request) as $file) {
                $path = $file->getRealPath();
                if ($path === false || ! is_file($path) || ! is_readable($path)) {
                    $validator->errors()->add('attachments', __('support-client::support.problems.file_missing'));

                    return;
                }
                $total += (int) $file->getSize();
            }
            if ($total > self::MAX_TOTAL_BYTES) {
                $validator->errors()->add('attachments', __('support-client::support.problems.too_large'));
            }
        });

        return $validator;
    }

    /**
     * The uploaded files in the shape SupportClient sends.
     *
     * @return list<array{path: string, name: string, type: string}>
     */
    protected function uploads(Request $request): array
    {
        return array_map(fn (UploadedFile $file) => [
            'path' => (string) $file->getRealPath(),
            'name' => $file->getClientOriginalName(),
            'type' => $file->getClientMimeType() ?: 'application/octet-stream',
        ], $this->files($request));
    }

    /**
     * @return list<UploadedFile>
     */
    protected function files(Request $request): array
    {
        $files = $request->file('attachments', []);

        return array_values(array_filter(
            is_array($files) ? $files : [$files],
            fn ($file) => $file instanceof UploadedFile && $file->isValid(),
        ));
    }

    /**
     * @return array{files: int, file_mb: int, total_mb: int, extensions: list<string>}
     */
    protected function limits(): array
    {
        return [
            'files' => self::MAX_FILES,
            'file_mb' => intdiv(self::MAX_FILE_KB, 1024),
            'total_mb' => intdiv(self::MAX_TOTAL_BYTES, 1024 * 1024),
            'extensions' => self::EXTENSIONS,
        ];
    }

    /**
     * The CRM's paging, without its URLs.
     *
     * @param  array<string, mixed>  $meta
     * @return array{current_page: int, last_page: int, per_page: int, total: int, from: int|null, to: int|null}
     */
    protected function meta(array $meta, int $count): array
    {
        $current = max(1, (int) ($meta['current_page'] ?? 1));

        return [
            'current_page' => $current,
            'last_page' => max($current, (int) ($meta['last_page'] ?? $current)),
            'per_page' => (int) ($meta['per_page'] ?? 20),
            'total' => (int) ($meta['total'] ?? $count),
            'from' => isset($meta['from']) ? (int) $meta['from'] : null,
            'to' => isset($meta['to']) ? (int) $meta['to'] : null,
        ];
    }

    /** Only an http(s) link is ever put in an href. */
    protected function safeUrl(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function jsonError(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return new JsonResponse(['error' => $code, 'message' => $message] + $extra, $status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function view(string $name, array $data = [], int $status = 200): Response
    {
        return response()->view('support-client::'.$name, $data, $status);
    }

    /**
     * Resolved per call: it carries the current locale (Accept-Language).
     * A missing or non-https SUPPORT_API_URL is logged for the developer and
     * shown to the user like an unreachable CRM, never as a server error.
     */
    protected function client(): SupportClient
    {
        try {
            return app(SupportClient::class);
        } catch (InvalidArgumentException $e) {
            Log::error('support-client: '.$e->getMessage().' Check SUPPORT_API_URL.');

            throw new TransportException('The support API is not configured.', 0, $e);
        }
    }
}
