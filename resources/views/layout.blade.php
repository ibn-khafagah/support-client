@use('IbnKhafagah\SupportClient\Laravel\View\Ui')
@use('IbnKhafagah\SupportClient\Laravel\Http\SupportController')
@php
    $appName = (string) (config('support-client.app_name') ?: config('app.name'));
    $notice = session(SupportController::FLASH_STATUS);
    $problem = $problem ?? session(SupportController::FLASH_PROBLEM);
@endphp
<!doctype html>
<html lang="{{ Ui::lang() }}" dir="{{ Ui::dir() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', Ui::t('title')) · {{ $appName }}</title>
    <style>
        :root {
            --sc-bg: #f4f6f9; --sc-card: #ffffff; --sc-text: #0f172a; --sc-muted: #64748b; --sc-line: #e2e8f0; --sc-soft: #f8fafc;
            --sc-brand: #0f766e; --sc-brand-ink: #ffffff; --sc-brand-soft: #f0fdfa; --sc-focus: #0284c7;
            --sc-red: #b91c1c; --sc-red-bg: #fee2e2; --sc-blue: #1d4ed8; --sc-blue-bg: #dbeafe;
            --sc-amber: #92400e; --sc-amber-bg: #fef3c7; --sc-green: #166534; --sc-green-bg: #dcfce7;
            --sc-slate: #475569; --sc-slate-bg: #e2e8f0;
            color-scheme: light;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --sc-bg: #0b1220; --sc-card: #111a2e; --sc-text: #e2e8f0; --sc-muted: #94a3b8; --sc-line: #1f2a40; --sc-soft: #0f172a;
                --sc-brand: #2dd4bf; --sc-brand-ink: #042f2e; --sc-brand-soft: #0f2a2a; --sc-focus: #38bdf8;
                --sc-red: #fca5a5; --sc-red-bg: #3b1215; --sc-blue: #93c5fd; --sc-blue-bg: #13234a;
                --sc-amber: #fcd34d; --sc-amber-bg: #3a2a0c; --sc-green: #86efac; --sc-green-bg: #0f2e1c;
                --sc-slate: #cbd5e1; --sc-slate-bg: #1e293b;
                color-scheme: dark;
            }
        }
        *, *::before, *::after { box-sizing: border-box; }
        body.sc { margin: 0; min-height: 100vh; background: var(--sc-bg); color: var(--sc-text);
                  font: 15px/1.7 system-ui, -apple-system, "Segoe UI", Tahoma, Arial, sans-serif; -webkit-text-size-adjust: 100%; }
        html[dir="rtl"] body.sc { font-family: Tahoma, "Segoe UI", system-ui, Arial, sans-serif; }
        .sc a { color: var(--sc-brand); }
        .sc :focus-visible { outline: 2px solid var(--sc-focus); outline-offset: 2px; }
        .sc h1 { font-size: 22px; line-height: 1.4; margin: 0; }
        .sc h2 { font-size: 17px; margin: 0 0 12px; }
        .sc-wrap { width: 100%; max-width: 980px; margin: 0 auto; padding: 0 16px; }
        .sc-top { background: var(--sc-card); border-bottom: 1px solid var(--sc-line); }
        .sc-top-in { display: flex; align-items: center; justify-content: space-between; gap: 8px 16px; min-height: 60px; flex-wrap: wrap; padding-block: 8px; }
        .sc-brand { font-weight: 700; font-size: 17px; color: var(--sc-text) !important; text-decoration: none; }
        .sc-brand small { display: block; font-weight: 400; font-size: 12px; color: var(--sc-muted); }
        .sc-nav { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .sc-main { padding-top: 24px; padding-bottom: 48px; }
        .sc-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
        .sc-card { background: var(--sc-card); border: 1px solid var(--sc-line); border-radius: 14px; padding: 20px; }
        .sc-stack > * + * { margin-top: 16px; }
        .sc-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 38px; padding: 7px 16px;
                  border: 1px solid var(--sc-line); border-radius: 10px; background: var(--sc-card); color: var(--sc-text) !important;
                  font: inherit; font-size: 14px; font-weight: 600; line-height: 1.4; text-decoration: none; cursor: pointer; white-space: nowrap; }
        .sc-btn:hover { background: var(--sc-soft); }
        .sc-btn-primary { background: var(--sc-brand); border-color: var(--sc-brand); color: var(--sc-brand-ink) !important; }
        .sc-btn-primary:hover { background: var(--sc-brand); filter: brightness(1.08); }
        .sc-btn-link { border-color: transparent; background: transparent; color: var(--sc-muted) !important; }
        .sc-btn[disabled] { opacity: .6; cursor: progress; }
        .sc-alert { border-radius: 12px; padding: 12px 16px; margin-bottom: 16px; }
        .sc-alert strong { display: block; }
        .sc-alert p { margin: 4px 0 0; }
        .sc-alert ul { margin: 4px 0 0; padding-inline-start: 20px; }
        .sc-alert .sc-btn { margin-top: 10px; }
        .sc-alert-ok { background: var(--sc-green-bg); color: var(--sc-green); }
        .sc-alert-bad { background: var(--sc-red-bg); color: var(--sc-red); }
        .sc-alert-info { background: var(--sc-blue-bg); color: var(--sc-blue); }
        .sc-alert-warn { background: var(--sc-amber-bg); color: var(--sc-amber); }
        .sc-outbox { margin-bottom: 16px; }
        .sc-outbox-list { list-style: none; margin: 0; padding: 0; }
        .sc-outbox-list li { display: flex; justify-content: space-between; align-items: flex-start; gap: 4px 16px; flex-wrap: wrap;
                             padding: 10px 0; border-top: 1px solid var(--sc-line); }
        .sc-outbox-list li:first-child { border-top: 0; }
        .sc-outbox-subject { font-weight: 600; overflow-wrap: anywhere; }
        .sc-outbox-state { text-align: end; max-width: 100%; }
        .sc-outbox-state p { margin: 4px 0 0; font-size: 13px; color: var(--sc-red); overflow-wrap: anywhere; }
        .sc-down { text-align: center; padding: 36px 20px; }
        .sc-down h1 { margin-bottom: 8px; }
        .sc-down p { margin: 0 auto 6px; max-width: 560px; }
        .sc-down .sc-actions { justify-content: center; margin-top: 16px; }
        .sc-badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; line-height: 1.7; white-space: nowrap; }
        .sc-tone-red { background: var(--sc-red-bg); color: var(--sc-red); }
        .sc-tone-blue { background: var(--sc-blue-bg); color: var(--sc-blue); }
        .sc-tone-amber { background: var(--sc-amber-bg); color: var(--sc-amber); }
        .sc-tone-green { background: var(--sc-green-bg); color: var(--sc-green); }
        .sc-tone-slate { background: var(--sc-slate-bg); color: var(--sc-slate); }
        .sc-chip { background: var(--sc-soft); color: var(--sc-muted); border: 1px solid var(--sc-line); font-weight: 600; }
        .sc-badges { display: flex; flex-wrap: wrap; gap: 6px; }
        .sc-mono { display: inline-block; font-family: ui-monospace, SFMono-Regular, Consolas, "Courier New", monospace; direction: ltr; unicode-bidi: isolate; }
        .sc-muted { color: var(--sc-muted); }
        .sc-small { font-size: 13px; }
        .sc-filters { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
        .sc-seg { display: inline-flex; flex-wrap: wrap; border: 1px solid var(--sc-line); border-radius: 10px; overflow: hidden; background: var(--sc-card); }
        .sc-seg a { padding: 6px 14px; font-size: 14px; color: var(--sc-muted); text-decoration: none; border-inline-start: 1px solid var(--sc-line); }
        .sc-seg a:first-child { border-inline-start: 0; }
        .sc-seg a[aria-current="page"] { background: var(--sc-brand); color: var(--sc-brand-ink); font-weight: 600; }
        .sc-table-wrap { padding: 0; overflow-x: auto; }
        .sc-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .sc-table th { text-align: start; color: var(--sc-muted); font-weight: 600; font-size: 12px; padding: 10px 14px; border-bottom: 1px solid var(--sc-line); white-space: nowrap; }
        .sc-table td { padding: 12px 14px; border-bottom: 1px solid var(--sc-line); vertical-align: middle; }
        .sc-table tbody tr:last-child td { border-bottom: 0; }
        .sc-table tbody tr:hover { background: var(--sc-soft); }
        .sc-table td a { color: var(--sc-text); font-weight: 600; text-decoration: none; }
        .sc-table td a:hover { color: var(--sc-brand); }
        .sc-nowrap { white-space: nowrap; }
        .sc-new { margin-inline-start: 6px; font-size: 11px; vertical-align: middle; }
        .sc-row-new td a { font-weight: 800; }
        .sc-empty { text-align: center; padding: 40px 20px; }
        .sc-empty p { margin: 0 0 6px; }
        .sc-empty .sc-btn { margin-top: 12px; }
        .sc-pager { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
        .sc-field { margin-bottom: 18px; }
        .sc-label { display: block; font-weight: 600; font-size: 14px; margin-bottom: 6px; }
        .sc-input { display: block; width: 100%; padding: 10px 12px; border: 1px solid var(--sc-line); border-radius: 10px;
                    background: var(--sc-soft); color: var(--sc-text); font: inherit; }
        .sc-input:focus { outline: 2px solid var(--sc-focus); outline-offset: 0; border-color: transparent; }
        textarea.sc-input { min-height: 170px; resize: vertical; }
        input[type="file"].sc-input { padding: 8px; }
        .sc-input[aria-invalid="true"] { border-color: var(--sc-red); }
        .sc-error { color: var(--sc-red); font-size: 13px; margin: 6px 0 0; }
        .sc-hint { color: var(--sc-muted); font-size: 13px; margin: 6px 0 0; }
        .sc-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
        .sc-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .sc-back { display: inline-block; margin-bottom: 12px; font-size: 14px; text-decoration: none; }
        .sc-ticket-head .sc-number { font-size: 13px; color: var(--sc-muted); }
        .sc-ticket-head h1 { margin: 2px 0 10px; overflow-wrap: anywhere; }
        .sc-meta { display: flex; flex-wrap: wrap; gap: 4px 22px; margin: 12px 0 0; font-size: 13px; color: var(--sc-muted); }
        .sc-meta dt, .sc-meta dd { display: inline; margin: 0; }
        .sc-meta dd { color: var(--sc-text); }
        .sc-thread { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 12px; }
        .sc-msg { background: var(--sc-card); border: 1px solid var(--sc-line); border-radius: 14px; padding: 14px 16px; }
        .sc-msg-staff { background: var(--sc-brand-soft); border-inline-start: 4px solid var(--sc-brand); }
        .sc-msg-head { display: flex; justify-content: space-between; align-items: baseline; gap: 4px 12px; flex-wrap: wrap; margin-bottom: 6px; font-size: 14px; }
        .sc-msg-body { white-space: pre-wrap; overflow-wrap: anywhere; }
        .sc-files { list-style: none; margin: 10px 0 0; padding: 0; display: flex; flex-wrap: wrap; gap: 8px; }
        .sc-files a { display: inline-flex; align-items: center; gap: 6px; max-width: 100%; padding: 4px 10px; border: 1px solid var(--sc-line);
                      border-radius: 8px; background: var(--sc-card); font-size: 13px; text-decoration: none; overflow-wrap: anywhere; }
        .sc-files svg { flex: none; }
        .sc-section-title { font-size: 15px; color: var(--sc-muted); margin: 24px 0 10px; }
        @media (max-width: 640px) {
            .sc-grid2 { grid-template-columns: 1fr; }
            .sc-card { padding: 16px; }
            .sc h1 { font-size: 20px; }
            /* the ticket list as stacked rows instead of a table that scrolls sideways */
            .sc-table thead { display: none; }
            .sc-table, .sc-table tbody, .sc-table tr, .sc-table td { display: block; width: 100%; }
            .sc-table tbody tr { padding: 12px 16px; border-bottom: 1px solid var(--sc-line); }
            .sc-table tbody tr:last-child { border-bottom: 0; }
            .sc-table td { padding: 2px 0; border: 0; }
            .sc-table td.sc-cell-meta { color: var(--sc-muted); font-size: 13px; }
        }
    </style>
</head>
<body class="sc">
    <header class="sc-top">
        <div class="sc-wrap sc-top-in">
            <a class="sc-brand" href="{{ route('support-client.index') }}">
                {{ Ui::t('title') }}
                @if ($appName !== '')
                    <small>{{ $appName }}</small>
                @endif
            </a>
            <nav class="sc-nav" aria-label="{{ Ui::t('nav.main') }}">
                <a class="sc-btn sc-btn-link" href="{{ url('/') }}">{{ Ui::t('nav.back', ['app' => $appName !== '' ? $appName : '/']) }}</a>
                <a class="sc-btn" href="{{ route('support-client.index') }}">{{ Ui::t('nav.tickets') }}</a>
                <a class="sc-btn sc-btn-primary" href="{{ route('support-client.create') }}">{{ Ui::t('nav.new') }}</a>
            </nav>
        </div>
    </header>

    <main class="sc-wrap sc-main">
        @if (is_string($notice) && $notice !== '')
            <div class="sc-alert sc-alert-ok" role="status">{{ $notice }}</div>
        @endif

        @if (is_array($problem))
            <div class="sc-alert sc-alert-bad" role="alert">
                @if (! empty($problem['title']))
                    <strong>{{ $problem['title'] }}</strong>
                @endif
                @if (! empty($problem['message']) && ($problem['message'] ?? '') !== ($problem['title'] ?? ''))
                    <p>{{ $problem['message'] }}</p>
                @endif
                @if (! empty($problem['renew_url']))
                    <a class="sc-btn sc-btn-primary" href="{{ $problem['renew_url'] }}" target="_blank" rel="noopener noreferrer">{{ Ui::t('problems.renew') }}</a>
                @endif
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
