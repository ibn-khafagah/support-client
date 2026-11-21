@extends('support-client::layout')
@use('IbnKhafagah\SupportClient\Laravel\View\Ui')

@section('title', ($ticket['number'] ?? '').' · '.($ticket['subject'] ?? ''))

@section('content')
    @php
        $number = (string) $ticket['number'];
        $status = (string) ($ticket['status'] ?? '');
        // why the reply box is hidden (support suspended or revoked), or null
        $replyBlock = is_array($replyBlock ?? null) ? $replyBlock : null;
        $replies = is_array($ticket['replies'] ?? null) ? $ticket['replies'] : [];
        $attachmentErrors = array_merge($errors->get('attachments'), $errors->get('attachments.*') ? array_merge(...array_values($errors->get('attachments.*'))) : []);
        $otherErrors = [];
        foreach ($errors->getMessages() as $field => $messages) {
            if (! in_array(explode('.', (string) $field)[0], ['body', 'attachments'], true)) {
                array_push($otherErrors, ...$messages);
            }
        }
    @endphp

    <a class="sc-back" href="{{ route('support-client.index') }}">{{ Ui::dir() === 'rtl' ? '→' : '←' }} {{ Ui::t('show.back') }}</a>

    <section class="sc-card sc-ticket-head">
        <div class="sc-number"><span class="sc-mono">{{ $number }}</span></div>
        <h1 dir="auto">{{ $ticket['subject'] ?? '' }}</h1>
        <div class="sc-badges">
            <span class="sc-badge sc-tone-{{ Ui::statusTone($status) }}">{{ Ui::status($ticket) }}</span>
            @if (! empty($ticket['priority']))
                <span class="sc-badge sc-tone-{{ Ui::priorityTone($ticket['priority']) }}">{{ Ui::priority($ticket['priority']) }}</span>
            @endif
            @if (! empty($ticket['category']['name']))
                <span class="sc-badge sc-chip">{{ $ticket['category']['name'] }}</span>
            @endif
        </div>
        <dl class="sc-meta">
            @if ($admin && ! empty($ticket['reporter']['name']))
                <div><dt>{{ Ui::t('show.opened_by') }}:</dt> <dd><bdi>{{ $ticket['reporter']['name'] }}</bdi></dd></div>
            @endif
            <div><dt>{{ Ui::t('show.opened_at') }}:</dt> <dd><time datetime="{{ $ticket['created_at'] ?? '' }}">{{ Ui::date($ticket['created_at'] ?? null) }}</time></dd></div>
            <div><dt>{{ Ui::t('show.updated_at') }}:</dt> <dd><time datetime="{{ $ticket['updated_at'] ?? '' }}">{{ Ui::date($ticket['updated_at'] ?? null) }}</time></dd></div>
            @if (! empty($ticket['closed_at']))
                <div><dt>{{ Ui::t('show.closed_at') }}:</dt> <dd><time datetime="{{ $ticket['closed_at'] }}">{{ Ui::date($ticket['closed_at']) }}</time></dd></div>
            @endif
        </dl>
    </section>

    @if ($status === 'resolved')
        <div class="sc-alert sc-alert-info" style="margin-top:16px">
            <strong>{{ Ui::t('show.resolved_prompt') }}</strong>
            <form method="post" action="{{ route('support-client.close', $number) }}">
                @csrf
                <button class="sc-btn sc-btn-primary" type="submit">{{ Ui::t('show.resolved_close') }}</button>
            </form>
            @if ($replyBlock === null)
                <p class="sc-small">{{ Ui::t('show.resolved_hint') }}</p>
            @endif
        </div>
    @endif

    <h2 class="sc-section-title">{{ Ui::t('show.conversation') }}</h2>
    @if ($replies === [])
        <p class="sc-muted">{{ Ui::t('show.no_replies') }}</p>
    @else
        <ol class="sc-thread">
            @foreach ($replies as $reply)
                @php $files = is_array($reply['attachments'] ?? null) ? $reply['attachments'] : []; @endphp
                <li class="sc-msg {{ Ui::isStaff($reply) ? 'sc-msg-staff' : 'sc-msg-client' }}">
                    <div class="sc-msg-head">
                        <strong><bdi>{{ Ui::author($reply) }}</bdi></strong>
                        <time class="sc-muted sc-small" datetime="{{ $reply['created_at'] ?? '' }}">{{ Ui::date($reply['created_at'] ?? null) }}</time>
                    </div>
                    <div class="sc-msg-body" dir="auto">{{ $reply['body'] ?? '' }}</div>
                    @if ($files !== [])
                        <ul class="sc-files" aria-label="{{ Ui::t('show.attachments') }}">
                            @foreach ($files as $file)
                                <li>
                                    <a href="{{ $file['url'] }}" aria-label="{{ Ui::t('show.download', ['name' => $file['name'] ?? '']) }}">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                                        <bdi>{{ $file['name'] ?? '' }}</bdi>
                                        <span class="sc-muted">({{ Ui::size($file['size'] ?? 0) }})</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    @if ($replyBlock !== null)
        <section class="sc-alert sc-alert-warn" style="margin-top:20px" role="note">
            <strong>{{ $replyBlock['title'] }}</strong>
            <p dir="auto">{{ $replyBlock['message'] }}</p>
            @if (! empty($replyBlock['renew_url']))
                <a class="sc-btn sc-btn-primary" href="{{ $replyBlock['renew_url'] }}" target="_blank" rel="noopener noreferrer">{{ Ui::t('problems.renew') }}</a>
            @endif
        </section>
    @else
    <section class="sc-card" style="margin-top:20px">
        <h2>{{ Ui::t('show.reply_title') }}</h2>
        @if ($status === 'closed')
            <p class="sc-hint" style="margin:-6px 0 12px">{{ Ui::t('show.closed_note') }}</p>
        @endif

        @if ($otherErrors !== [])
            <div class="sc-alert sc-alert-bad" role="alert">
                <ul>
                    @foreach ($otherErrors as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('support-client.reply', $number) }}" enctype="multipart/form-data"
              onsubmit="var b=this.querySelector('[type=submit]');if(b){b.disabled=true;}">
            @csrf
            <div class="sc-field">
                <label class="sc-label" for="sc-reply">{{ Ui::t('show.reply_label') }}</label>
                <textarea class="sc-input" id="sc-reply" name="body" rows="5" maxlength="20000" required dir="auto"
                          placeholder="{{ Ui::t('show.reply_placeholder') }}"
                          @error('body') aria-invalid="true" aria-describedby="sc-reply-error" @enderror>{{ old('body') }}</textarea>
                @error('body')
                    <p class="sc-error" id="sc-reply-error">{{ $message }}</p>
                @enderror
            </div>
            <div class="sc-field">
                <label class="sc-label" for="sc-reply-files">{{ Ui::t('labels.attachments') }}</label>
                <input class="sc-input" id="sc-reply-files" name="attachments[]" type="file" multiple
                       accept="{{ implode(',', array_map(fn ($ext) => '.'.$ext, $limits['extensions'])) }}"
                       aria-describedby="sc-reply-files-hint" @if ($attachmentErrors !== []) aria-invalid="true" @endif>
                <p class="sc-hint" id="sc-reply-files-hint">{{ Ui::t('create.attachments_hint', ['count' => $limits['files'], 'size' => $limits['file_mb'], 'total' => $limits['total_mb'], 'types' => implode(', ', $limits['extensions'])]) }}</p>
                @foreach (array_unique($attachmentErrors) as $message)
                    <p class="sc-error">{{ $message }}</p>
                @endforeach
            </div>
            <div class="sc-actions">
                <button class="sc-btn sc-btn-primary" type="submit">{{ Ui::t('show.reply_submit') }}</button>
            </div>
        </form>
    </section>
    @endif

    @if (! in_array($status, ['closed', 'resolved'], true))
        <form method="post" action="{{ route('support-client.close', $number) }}" style="margin-top:12px"
              onsubmit="return confirm(@js(Ui::t('show.close_confirm')));">
            @csrf
            <button class="sc-btn sc-btn-link" type="submit">{{ Ui::t('show.close') }}</button>
        </form>
    @endif
@endsection
