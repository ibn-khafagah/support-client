@extends('support-client::layout')
@use('IbnKhafagah\SupportClient\Laravel\View\Ui')

{{-- a page that could not be loaded from the support service: the CRM is unreachable, or refused the read --}}
@php
    $failure = is_array($failure ?? null) ? $failure : [];
    $heading = ! empty($failure['title']) ? (string) $failure['title'] : Ui::t('unavailable.title');
    $text = (string) ($failure['message'] ?? '');
@endphp

@section('title', $heading)

@section('content')
    @include('support-client::partials.outbox', ['outbox' => $outbox ?? []])

    <section class="sc-card sc-down" role="alert">
        <h1>{{ $heading }}</h1>
        @if ($text !== '' && $text !== $heading)
            <p dir="auto">{{ $text }}</p>
        @endif
        <div class="sc-actions">
            @if (! empty($failure['renew_url']))
                <a class="sc-btn sc-btn-primary" href="{{ $failure['renew_url'] }}" target="_blank" rel="noopener noreferrer">{{ Ui::t('problems.renew') }}</a>
            @endif
            <a class="sc-btn" href="{{ url()->full() }}">{{ Ui::t('unavailable.retry') }}</a>
        </div>
    </section>
@endsection
