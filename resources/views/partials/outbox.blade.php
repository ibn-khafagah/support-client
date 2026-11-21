@use('IbnKhafagah\SupportClient\Laravel\View\Ui')
{{-- the user's own tickets saved while the support service could not be reached --}}
@if (! empty($outbox))
    @php $anyFailed = in_array('failed', array_column($outbox, 'state'), true); @endphp
    <section class="sc-card sc-outbox" aria-labelledby="sc-outbox-title">
        <h2 id="sc-outbox-title">{{ Ui::t('outbox.title') }}</h2>
        <p class="sc-hint" style="margin:-6px 0 8px">{{ Ui::t('outbox.hint') }}</p>
        <ul class="sc-outbox-list">
            @foreach ($outbox as $entry)
                <li>
                    <div>
                        <div class="sc-outbox-subject" dir="auto">{{ $entry['subject'] }}</div>
                        <div class="sc-muted sc-small"><time datetime="{{ $entry['created_at'] }}">{{ Ui::t('outbox.saved_at', ['time' => Ui::date($entry['created_at'])]) }}</time></div>
                    </div>
                    <div class="sc-outbox-state">
                        @if ($entry['state'] === 'failed')
                            <p dir="auto">{{ Ui::t('outbox.failed', ['reason' => (string) ($entry['reason'] ?? '')]) }}</p>
                        @else
                            <span class="sc-badge sc-tone-amber">{{ Ui::t('outbox.pending') }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        @if ($anyFailed)
            <p class="sc-hint" style="margin:8px 0 0">{{ Ui::t('outbox.failed_hint') }}</p>
        @endif
    </section>
@endif
