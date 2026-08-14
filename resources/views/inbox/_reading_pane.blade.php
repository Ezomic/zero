@php
    $rpInitials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $email->from_name ?: $email->from_address), 0, 2)) ?: '??';
@endphp
<div class="rp-header">
    <a href="{{ route('inbox.index') }}" class="rp-back" aria-label="Back to inbox">
        <svg class="ic-sm"><use href="#i-back"/></svg> Inbox
    </a>
    <div>
        <h2>
            {{ $email->subject }}
            @if (count($messages) > 1)
                <span class="rp-count" title="{{ count($messages) }} messages in this conversation">{{ count($messages) }}</span>
            @endif
        </h2>
        <div class="rp-chips">
            {{-- The sender is a link to everything they have sent, which is
                 usually the question worth asking about them (ZERO-122). --}}
            @if ($email->from_address)
                <a class="chip" href="{{ route('sender.show', ['address' => $email->from_address]) }}"
                   title="All mail from {{ $email->from_address }}">
                    <span class="dot" style="background:{{ $email->mailAccount->color }}">{{ $rpInitials }}</span>
                    {{ $email->from_name ?: $email->from_address }}
                </a>
            @else
                <div class="chip">
                    <span class="dot" style="background:{{ $email->mailAccount->color }}">{{ $rpInitials }}</span>
                    {{ $email->from_name ?: $email->from_address }}
                </div>
            @endif
            <div class="chip" style="color:var(--text-faint);">via {{ $email->mailAccount->email_address }}</div>
        </div>
        @php
            $rpTo = $email->recipientList();
            $rpCc = $email->ccList();
        @endphp
        @if ($rpTo)
            <div class="rp-recipients" style="color:var(--text-faint); font-size:13px; margin-top:4px;">
                <span style="font-weight:600;">To:</span> {{ implode(', ', $rpTo) }}
            </div>
        @endif
        @if ($rpCc)
            <div class="rp-recipients" style="color:var(--text-faint); font-size:13px;">
                <span style="font-weight:600;">Cc:</span> {{ implode(', ', $rpCc) }}
            </div>
        @endif
    </div>
    <div class="rp-actions">
        @if ($email->is_archived)
            <form method="POST" action="{{ route('inbox.unarchive', $email) }}">
                @csrf
                <button class="icon-btn" title="Move to inbox"><svg class="ic-sm"><use href="#i-archive"/></svg></button>
            </form>
        @else
            <form method="POST" action="{{ route('inbox.archive', $email) }}">
                @csrf
                <button class="icon-btn" title="Archive"><svg class="ic-sm"><use href="#i-archive"/></svg></button>
            </form>
        @endif
        <form method="POST" action="{{ route('inbox.toggleMute', $email) }}">
            @csrf
            <button class="icon-btn {{ ($threadIsMuted ?? false) ? 'active' : '' }}"
                    title="{{ ($threadIsMuted ?? false) ? 'Unmute: replies will reach the inbox again' : 'Mute: later replies skip the inbox' }}">
                <svg class="ic-sm"><use href="#i-mute"/></svg>
            </button>
        </form>
        {{-- Local to Zero: IMAP has no concept of snoozing, and inventing a
             folder for it would make the message vanish from every other
             client (ZERO-114). --}}
        <div class="snooze-menu" x-data="{ open: false }" x-on:keydown.escape="open = false">
            <button type="button" class="icon-btn {{ $email->snoozed_until ? 'active' : '' }}"
                    x-on:click="open = ! open" :aria-expanded="open"
                    title="Snooze: hide it here until later, nothing changes on the server">
                <svg class="ic-sm"><use href="#i-calendar"/></svg>
            </button>
            <div class="snooze-pop" x-show="open" x-cloak x-on:click.outside="open = false">
                <form method="POST" action="{{ route('inbox.snooze', $email) }}">
                    @csrf
                    @foreach (\App\Support\SnoozePresets::options() as $key => $label)
                        <button class="snooze-opt" name="preset" value="{{ $key }}">
                            {{ $label }}
                            <span>{{ \App\Support\SnoozePresets::resolve($key)?->format('D H:i') }}</span>
                        </button>
                    @endforeach
                    <label class="snooze-at">
                        <span>Or pick a time</span>
                        <input type="datetime-local" name="until">
                    </label>
                    <button class="btn sm ghost" style="width:100%;">Snooze</button>
                </form>
                @if ($email->snoozed_until)
                    <form method="POST" action="{{ route('inbox.unsnooze', $email) }}" style="margin-top:6px;">
                        @csrf @method('DELETE')
                        <button class="btn sm ghost" style="width:100%;">Unsnooze</button>
                    </form>
                @endif
                <p class="snooze-note">Only in Zero. The message stays where it is on the mail server.</p>
            </div>
        </div>
        <form method="POST" action="{{ route('inbox.markUnread', $email) }}">
            @csrf
            <button class="icon-btn" title="Mark unread"><svg class="ic-sm"><use href="#i-check"/></svg></button>
        </form>
        <form method="POST" action="{{ route('inbox.destroy', $email) }}" onsubmit="return confirm('Delete this conversation?')">
            @csrf @method('DELETE')
            <button class="icon-btn" title="Delete" style="color:var(--danger);"><svg class="ic-sm"><use href="#i-trash"/></svg></button>
        </form>
    </div>
</div>

@php
    // The newest message that offers a way off the list. A thread is usually
    // one newsletter, but a reply chain would otherwise hide the header behind
    // whichever message happens to anchor the thread (ZERO-115).
    $unsubscribeFrom = collect($messages)->reverse()->first(fn ($m) => $m->unsubscribeOptions() !== null);
    $unsubscribe = $unsubscribeFrom?->unsubscribeOptions();
@endphp
@if ($unsubscribe)
    <div class="unsub">
        <svg class="ic-sm"><use href="#i-mute"/></svg>
        <span>This is a mailing list.</span>
        {{-- oneClick, not isPostable(): the latter resolves the host, and a
             DNS lookup per render is not something a reading pane should do.
             The controller runs the real check when the button is pressed. --}}
        @if ($unsubscribe->oneClick)
            <form method="POST" action="{{ route('inbox.unsubscribe', $unsubscribeFrom) }}">
                @csrf
                <button class="btn sm ghost">Unsubscribe</button>
            </form>
        @endif
        @if ($unsubscribe->url)
            {{-- Kept alongside the button, not just as its replacement: when
                 the POST is refused this is the only way left. --}}
            <a class="btn sm ghost" href="{{ $unsubscribe->url }}" target="_blank" rel="noopener noreferrer nofollow">
                {{ $unsubscribe->oneClick ? 'Open their page' : 'Unsubscribe on their site' }}
            </a>
        @elseif ($unsubscribe->mailto)
            <a class="btn sm ghost" href="{{ $unsubscribe->mailto }}">Unsubscribe by email</a>
        @endif
        @if ($unsubscribeFrom->from_address)
            <a class="unsub-rest" href="{{ route('sender.show', ['address' => $unsubscribeFrom->from_address]) }}">
                Clean up the rest
            </a>
        @endif
    </div>
@endif

@if (count($availableFolders) > 1)
    <div style="padding:14px 22px 0;">
        <form method="POST" action="{{ route('inbox.move', $email) }}" style="display:flex; gap:8px;">
            @csrf
            <select name="folder" required style="padding:7px 10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-2); color:var(--text); font-size:12.5px;">
                <option value="" disabled {{ $suggestedFolder ? '' : 'selected' }}>Move to&hellip;</option>
                @foreach ($availableFolders as $f)
                    @unless ($f === $email->folder)
                        <option value="{{ $f }}" @selected($f === $suggestedFolder)>
                            {{ \App\Models\MailFolder::displayName($f) }}{{ $f === $suggestedFolder ? ' (suggested)' : '' }}
                        </option>
                    @endunless
                @endforeach
            </select>
            <button class="btn sm ghost">Move</button>
        </form>
    </div>
@endif

<div class="rp-body">
    {{-- Oldest first, so the last one is the newest: that is the one worth
         reading on open, and the rest collapse to a summary line. --}}
    @foreach ($messages as $message)
        @include('inbox._message', [
            'message' => $message,
            'expanded' => $loop->last,
            'invitation' => ($invitations ?? collect())->get($message->id),
        ])
    @endforeach
</div>
