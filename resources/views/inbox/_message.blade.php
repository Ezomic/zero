@php
    use Illuminate\Support\Str;

    $sender = $message->from_name ?: $message->from_address;
    $initials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $sender), 0, 2)) ?: '??';
    $outgoing = $message->isFromOwner();

    // Older messages come in collapsed; the caller marks the newest one open.
    $expanded = $expanded ?? true;

    $snippet = Str::limit(
        trim(preg_replace('/\s+/', ' ', $message->body_text ?: strip_tags((string) $message->body_html))),
        120,
    );
@endphp
<div class="msg{{ $outgoing ? ' outgoing' : '' }}" x-data="{ open: {{ $expanded ? 'true' : 'false' }} }" :class="{ 'msg-collapsed': ! open }">
    <div
        class="msg-head"
        role="button"
        tabindex="0"
        :aria-expanded="open"
        x-on:click="open = ! open"
        x-on:keydown.enter="open = ! open"
        x-on:keydown.space.prevent="open = ! open"
    >
        <div class="avatar" style="background:{{ \App\Support\AvatarColor::forAddress($message->from_address) }}; width:30px; height:30px; font-size:11px;">{{ $initials }}</div>
        <div class="msg-identity">
            <span class="who">{{ $sender }}</span>
            <span class="addr" x-show="open" x-cloak>&lt;{{ $message->from_address }}&gt;</span>
            @if ($snippet !== '')
                <span class="msg-snippet" x-show="! open" x-cloak>{{ $snippet }}</span>
            @endif
        </div>
        @if ($outgoing)
            <span class="msg-tag">You</span>
        @endif
        <div class="when">{{ $message->sent_at?->format('M j, Y g:i A') }}</div>
    </div>

    <div x-show="open" x-cloak>
        <div style="padding:0 16px 10px; font-size:11.5px; color:var(--text-faint); display:flex; align-items:center; gap:6px; margin-top:-8px;">
            <span class="acct-dot" style="background:{{ $message->mailAccount->color }}"></span>
            via {{ $message->mailAccount->email_address }} &middot; {{ \App\Models\MailFolder::displayName($message->folder) }}
        </div>

        @if ($message->body_html)
            <div class="msg-body" style="padding-top:0; border-top:none;">
                <iframe
                    srcdoc="{{ $message->body_html }}"
                    sandbox="allow-same-origin"
                    referrerpolicy="no-referrer"
                    style="min-height:120px;"
                    onload="this.style.height = (this.contentDocument.documentElement.scrollHeight + 16) + 'px'"
                ></iframe>
            </div>
        @elseif ($message->body_text)
            <div class="msg-body" style="padding-top:0; border-top:none; white-space:pre-wrap;">{{ $message->body_text }}</div>
        @endif

        @if ($message->attachments->isNotEmpty())
            <div class="attach-row">
                @foreach ($message->attachments as $attachment)
                    <a class="attach-chip" href="{{ route('attachments.download', $attachment) }}"
                       download="{{ $attachment->filename }}"
                       title="Download {{ $attachment->filename }}"><svg class="ic-sm"><use href="#i-clip"/></svg>{{ $attachment->filename }} &middot; {{ number_format(($attachment->size_bytes ?? 0) / 1024, 1) }} KB</a>
                @endforeach
            </div>
        @endif

        @php
            $calendarEvents = $message->calendarEvents;
        @endphp

        @if ($calendarEvents->isNotEmpty())
            <div class="attach-row">
                @foreach ($calendarEvents as $event)
                    @if ($event->url)
                        <a class="attach-chip" href="{{ $event->url }}" target="_blank" rel="noopener"
                           title="Open in the calendar"><svg class="ic-sm"><use href="#i-calendar"/></svg>{{ $event->title }} &middot; {{ $event->localStart()->format('j M, H:i') }}</a>
                    @else
                        <span class="attach-chip"><svg class="ic-sm"><use href="#i-calendar"/></svg>{{ $event->title }} &middot; {{ $event->localStart()->format('j M, H:i') }}</span>
                    @endif
                @endforeach
            </div>
        @endif

        <div style="display:flex; gap:6px; padding:12px 16px; border-top:1px solid var(--border-soft);">
            <a href="{{ route('compose.reply', $message) }}" class="btn sm ghost"><svg class="ic-sm"><use href="#i-reply"/></svg>Reply</a>
            <a href="{{ route('compose.replyAll', $message) }}" class="btn sm ghost">Reply All</a>
            <a href="{{ route('compose.forward', $message) }}" class="btn sm ghost">Forward</a>
            @if (config('services.calendar.token'))
                <button type="button" class="btn sm ghost" x-on:click="$dispatch('open-modal', 'cal-{{ $message->id }}')"><svg class="ic-sm"><use href="#i-calendar"/></svg>{{ $calendarEvents->isEmpty() ? 'Create event' : 'Create another event' }}</button>
            @endif
        </div>
    </div>

    @if (config('services.calendar.token'))
        @php
            $eventStart = \Illuminate\Support\Carbon::now()->addHour()->startOfHour();
        @endphp
        <x-modal name="cal-{{ $message->id }}" maxWidth="md" focusable>
            <form method="POST" action="{{ route('inbox.calendarEvent', $message) }}"
                  x-data="{ tz: Intl.DateTimeFormat().resolvedOptions().timeZone }"
                  style="padding:20px; display:flex; flex-direction:column; gap:12px;">
                @csrf
                <input type="hidden" name="timezone" x-model="tz">
                <h3 style="margin:0; font-weight:600;">Create calendar event</h3>
                @if ($calendarEvents->isNotEmpty())
                    <p style="margin:0; font-size:12.5px; color:var(--text-dim);">
                        Already in the calendar from this message:
                        {{ $calendarEvents->map(fn ($e) => $e->title.' ('.$e->localStart()->format('j M, H:i').')')->implode(', ') }}.
                        Submitting will create another.
                    </p>
                @endif
                <label style="display:flex; flex-direction:column; gap:4px; font-size:13px;">
                    Title
                    <input name="title" value="{{ $message->subject }}" required class="input">
                </label>
                <div style="display:flex; gap:12px;">
                    <label style="display:flex; flex-direction:column; gap:4px; font-size:13px; flex:1;">
                        Starts
                        <input type="datetime-local" name="starts_at" value="{{ $eventStart->format('Y-m-d\TH:i') }}" required class="input">
                    </label>
                    <label style="display:flex; flex-direction:column; gap:4px; font-size:13px; flex:1;">
                        Ends
                        <input type="datetime-local" name="ends_at" value="{{ $eventStart->copy()->addMinutes(30)->format('Y-m-d\TH:i') }}" required class="input">
                    </label>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" class="btn sm ghost" x-on:click="$dispatch('close-modal', 'cal-{{ $message->id }}')">Cancel</button>
                    <button type="submit" class="btn sm">Create event</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
