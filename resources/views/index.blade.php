@extends('support-client::layout')
@use('IbnKhafagah\SupportClient\Laravel\View\Ui')

@section('title', $admin && $scope === 'all' ? Ui::t('index.all_title') : Ui::t('index.title'))

@section('content')
    @php
        $failed = $failed ?? false;
        $unread = $unread ?? [];
        $statusTabs = ['' => 'filter_all', 'open' => 'filter_open', 'resolved' => 'filter_resolved', 'closed' => 'filter_closed'];
    @endphp

    <div class="sc-head">
        <h1>{{ $admin && $scope === 'all' ? Ui::t('index.all_title') : Ui::t('index.title') }}</h1>
    </div>

    @include('support-client::partials.outbox', ['outbox' => $outbox ?? []])

    @unless ($failed)
        <div class="sc-filters">
            @if ($admin)
                <nav class="sc-seg" aria-label="{{ Ui::t('index.scope_label') }}">
                    <a href="{{ route('support-client.index', array_filter(['status' => $status])) }}" @if ($scope === 'all') aria-current="page" @endif>{{ Ui::t('index.scope_all') }}</a>
                    <a href="{{ route('support-client.index', array_filter(['status' => $status, 'scope' => 'mine'])) }}" @if ($scope === 'mine') aria-current="page" @endif>{{ Ui::t('index.scope_mine') }}</a>
                </nav>
            @endif
            <nav class="sc-seg" aria-label="{{ Ui::t('index.status_label') }}">
                @foreach ($statusTabs as $value => $label)
                    <a href="{{ route('support-client.index', array_filter(['status' => $value, 'scope' => $admin && $scope === 'mine' ? 'mine' : null])) }}"
                       @if ((string) $status === $value) aria-current="page" @endif>{{ Ui::t('index.'.$label) }}</a>
                @endforeach
            </nav>
        </div>

        @if ($tickets === [])
            <div class="sc-card sc-empty">
                <p><strong>{{ $status !== null ? Ui::t('index.empty_filtered') : Ui::t('index.empty') }}</strong></p>
                <p class="sc-muted">{{ Ui::t('index.empty_hint') }}</p>
                <a class="sc-btn sc-btn-primary" href="{{ route('support-client.create') }}">{{ Ui::t('nav.new') }}</a>
            </div>
        @else
            <div class="sc-card sc-table-wrap">
                <table class="sc-table">
                    <thead>
                        <tr>
                            <th scope="col">{{ Ui::t('index.number') }}</th>
                            <th scope="col">{{ Ui::t('index.subject') }}</th>
                            <th scope="col">{{ Ui::t('index.category') }}</th>
                            <th scope="col">{{ Ui::t('index.status') }}</th>
                            @if ($admin)
                                <th scope="col">{{ Ui::t('index.reporter') }}</th>
                            @endif
                            <th scope="col">{{ Ui::t('index.last_activity') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tickets as $ticket)
                            @php
                                $url = route('support-client.show', $ticket['number']);
                                $isNew = isset($unread[(string) $ticket['number']]);
                            @endphp
                            <tr @if ($isNew) class="sc-row-new" @endif>
                                <td class="sc-nowrap"><a class="sc-mono" href="{{ $url }}">{{ $ticket['number'] }}</a></td>
                                <td dir="auto">
                                    <a href="{{ $url }}">{{ $ticket['subject'] ?? '' }}</a>
                                    @if ($isNew)
                                        <span class="sc-badge sc-tone-red sc-new" title="{{ Ui::t('index.new_hint') }}">{{ Ui::t('index.new') }}</span>
                                    @endif
                                </td>
                                <td class="sc-cell-meta">{{ $ticket['category']['name'] ?? '—' }}</td>
                                <td><span class="sc-badge sc-tone-{{ Ui::statusTone($ticket['status'] ?? null) }}">{{ Ui::status($ticket) }}</span></td>
                                @if ($admin)
                                    <td class="sc-cell-meta"><bdi>{{ $ticket['reporter']['name'] ?? '—' }}</bdi></td>
                                @endif
                                <td class="sc-nowrap sc-muted sc-cell-meta">
                                    @php $last = $ticket['last_reply_at'] ?? $ticket['updated_at'] ?? null; @endphp
                                    <time datetime="{{ $last }}">{{ Ui::date($last) }}</time>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($meta !== null && $meta['last_page'] > 1)
                <nav class="sc-pager" aria-label="{{ Ui::t('index.pages') }}">
                    @if ($links['prev'])
                        <a class="sc-btn" href="{{ $links['prev'] }}" rel="prev">{{ Ui::t('index.prev') }}</a>
                    @else
                        <span></span>
                    @endif
                    <span class="sc-muted sc-small">{{ Ui::t('index.page', ['current' => $meta['current_page'], 'last' => $meta['last_page']]) }}</span>
                    @if ($links['next'])
                        <a class="sc-btn" href="{{ $links['next'] }}" rel="next">{{ Ui::t('index.next') }}</a>
                    @else
                        <span></span>
                    @endif
                </nav>
            @endif
        @endif
    @endunless
@endsection
