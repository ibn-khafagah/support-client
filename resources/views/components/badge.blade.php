{{-- Self-contained: the host's layout may use any CSS framework, or none. Restyle .scb-badge in your own CSS. --}}
@once
    <style>
        .scb-badge { display: inline-block; min-width: 1.6em; padding: 0 .45em; border-radius: 999px; background: #dc2626; color: #fff;
                     font: 700 12px/1.6 system-ui, -apple-system, "Segoe UI", Tahoma, Arial, sans-serif; text-align: center; vertical-align: middle; }
    </style>
@endonce
<span {{ $attributes->merge(['class' => 'scb-badge']) }} title="{{ $label }}" aria-label="{{ $label }}">{{ $count > 99 ? '99+' : $count }}</span>
