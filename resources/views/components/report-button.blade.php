@use('IbnKhafagah\SupportClient\Laravel\View\Ui')
{{-- Self-contained: the host's error page may use any CSS framework, or none. --}}
<div {{ $attributes->merge(['class' => 'scb']) }} dir="{{ Ui::dir() }}" lang="{{ Ui::lang() }}">
    <style>
        .scb { --scb-brand: #0f766e; --scb-ink: #ffffff; --scb-text: inherit; --scb-muted: #64748b; --scb-line: #cbd5e1;
               margin: 24px auto 0; max-width: 520px; text-align: center; font: 15px/1.7 system-ui, -apple-system, "Segoe UI", Tahoma, Arial, sans-serif; }
        @media (prefers-color-scheme: dark) { .scb { --scb-brand: #2dd4bf; --scb-ink: #042f2e; --scb-muted: #94a3b8; --scb-line: #334155; } }
        .scb-ref { margin: 0 0 12px; color: var(--scb-muted); font-size: 14px; }
        .scb-code { display: inline-block; padding: 1px 8px; border: 1px solid var(--scb-line); border-radius: 6px; color: var(--scb-text);
                    font-family: ui-monospace, SFMono-Regular, Consolas, "Courier New", monospace; font-weight: 700; direction: ltr; unicode-bidi: isolate; }
        .scb-btn { display: inline-block; padding: 9px 20px; border-radius: 10px; background: var(--scb-brand); color: var(--scb-ink) !important;
                   font-weight: 600; text-decoration: none; }
        .scb-btn:hover { filter: brightness(1.08); }
        .scb-btn:focus-visible { outline: 2px solid var(--scb-brand); outline-offset: 3px; }
        .scb-hint { margin: 10px 0 0; color: var(--scb-muted); font-size: 13px; }
    </style>
    @if ($ref)
        <p class="scb-ref">{{ Ui::t('report.ref') }} <span class="scb-code">{{ $ref }}</span></p>
    @endif
    <a class="scb-btn" href="{{ $href }}">{{ Ui::t('report.action') }}</a>
    <p class="scb-hint">{{ Ui::t($ref ? 'report.hint' : 'report.hint_no_ref') }}</p>
</div>
