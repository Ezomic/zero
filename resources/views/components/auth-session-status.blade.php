@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'flash success']) }}>
        {{ $status }}
    </div>
@endif
