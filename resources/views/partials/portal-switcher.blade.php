@php
    $portalUser = auth()->user();
    $portal = $portalUser
        ? app(\App\Services\Portal\IdPortalClient::class)->appsFor($portalUser)
        : ['apps' => [], 'categories' => []];
    $portalSections = array_values(array_filter([
        ['label' => null, 'apps' => $portal['apps']],
        ...array_map(
            fn ($group) => ['label' => $group['category'], 'apps' => $group['apps']],
            $portal['categories'],
        ),
    ], fn ($section) => ! empty($section['apps'])));
@endphp

@if (! empty($portalSections))
    <div x-data="{ open: false }" style="position:relative;" @keydown.escape.window="open = false">
        <button type="button" class="nav-item" style="width:100%;" @click="open = ! open" :aria-expanded="open" aria-label="Switch app">
            <svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="7" height="7" rx="1"/>
                <rect x="14" y="3" width="7" height="7" rx="1"/>
                <rect x="3" y="14" width="7" height="7" rx="1"/>
                <rect x="14" y="14" width="7" height="7" rx="1"/>
            </svg>Apps
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition
            @click.outside="open = false"
            style="position:absolute; bottom:calc(100% + 6px); left:0; z-index:50; width:260px; padding:8px; border:1px solid var(--border); border-radius:12px; background:var(--bg); box-shadow:var(--shadow);"
        >
            <div style="padding:4px 6px 8px; font-size:11px; color:var(--text-dim);">Your apps</div>
            @foreach ($portalSections as $section)
                @if ($section['label'])
                    <div style="padding:8px 6px 4px; font-size:10px; font-weight:600; letter-spacing:0.05em; text-transform:uppercase; color:var(--text-dim);">{{ $section['label'] }}</div>
                @endif
                <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:4px;">
                    @foreach ($section['apps'] as $app)
                        <a
                            @if ($app['current']) aria-current="page" @endif
                            href="{{ $app['current'] ? '#' : $app['launch_url'] }}"
                            @style([
                                'display:flex; flex-direction:column; align-items:center; gap:6px; padding:8px 4px; border-radius:8px; text-align:center; text-decoration:none; color:var(--text);',
                                'background:var(--bg-2); pointer-events:none;' => $app['current'],
                            ])
                        >
                            <span
                                x-data="{ ok: true }"
                                style="display:flex; width:36px; height:36px; align-items:center; justify-content:center; overflow:hidden; border-radius:9px; font-size:12px; font-weight:600; color:#fff; background:{{ $app['accent'] ?? '#6b7280' }};"
                            >
                                <img
                                    x-show="ok"
                                    src="{{ rtrim($app['launch_url'], '/') }}/favicon.svg"
                                    alt=""
                                    style="width:100%; height:100%; object-fit:contain; padding:4px;"
                                    x-on:error="ok = false"
                                >
                                <span x-show="! ok" x-cloak>{{ $app['initials'] }}</span>
                            </span>
                            <span style="max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:11px; font-weight:500;">{{ $app['name'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
@endif
