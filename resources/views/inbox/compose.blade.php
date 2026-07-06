@extends('layouts.app')

@section('title', 'Compose')

@section('content')
    <div class="backdrop"></div>
    <div class="composer">
        <form method="POST" action="{{ route('compose.store') }}" enctype="multipart/form-data" id="compose-form">
            @csrf

            <input type="hidden" name="draft_id" id="draft_id" value="{{ $prefill['draft_id'] }}">
            <input type="hidden" name="in_reply_to" id="in_reply_to" value="{{ $prefill['in_reply_to'] }}">
            <input type="hidden" name="references" id="references" value="{{ $prefill['references'] }}">

            <div class="composer-head">
                <span class="title">New message</span>
                <a href="{{ route('inbox.index') }}" class="icon-btn" style="width:26px; height:26px;"><svg class="ic-sm"><use href="#i-x"/></svg></a>
            </div>

            <div class="composer-body">
                <div class="cfield">
                    <label>From</label>
                    <select name="mail_account_id" required>
                        @foreach ($accounts as $acc)
                            <option value="{{ $acc->id }}" @selected($prefill['mail_account_id'] == $acc->id)>{{ $acc->email_address }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="cfield">
                    <label>To</label>
                    <input type="text" name="to" id="field-to" value="{{ $prefill['to'] }}" placeholder="someone@example.com, another@example.com" autocomplete="off" required>
                    <div class="contact-dropdown hidden"></div>
                </div>

                <div class="cfield">
                    <label>Cc</label>
                    <input type="text" name="cc" id="field-cc" value="{{ $prefill['cc'] }}" autocomplete="off">
                    <div class="contact-dropdown hidden"></div>
                </div>

                <div class="cfield subject">
                    <label>Subject</label>
                    <input type="text" name="subject" id="field-subject" value="{{ $prefill['subject'] }}" required>
                </div>

                <textarea name="body" id="field-body" class="composer-textarea" placeholder="Write your message&hellip;" required>{{ $prefill['body'] }}</textarea>

                <div>
                    <label class="btn sm ghost" style="width:fit-content; cursor:pointer;">
                        <svg class="ic-sm"><use href="#i-clip"/></svg>Attach files
                        <input type="file" name="attachments[]" id="attachment-input" multiple style="position:absolute; width:1px; height:1px; overflow:hidden; opacity:0;">
                    </label>
                    <ul id="attachment-list" class="attach-list" style="margin-top:8px;"></ul>
                </div>
            </div>

            <div class="composer-foot">
                <button class="btn primary">Send</button>
                <a href="{{ route('drafts.index') }}" class="btn sm ghost">Saved drafts</a>
                <span id="draft-status" class="draft-status"></span>
            </div>
        </form>
    </div>
@endsection

@section('scripts')
    <script>
        (function () {
            const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
            const form = document.getElementById('compose-form');
            const draftIdField = document.getElementById('draft_id');
            const statusEl = document.getElementById('draft-status');

            // --- Draft autosave ---
            let saveTimer = null;

            function scheduleSave() {
                clearTimeout(saveTimer);
                saveTimer = setTimeout(saveDraft, 2000);
            }

            function saveDraft() {
                const to = document.getElementById('field-to').value.trim();
                const subject = document.getElementById('field-subject').value.trim();
                const body = document.getElementById('field-body').value.trim();

                if (!to && !subject && !body) {
                    return;
                }

                statusEl.textContent = 'Saving…';

                fetch('{{ route('drafts.autosave') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        draft_id: draftIdField.value || null,
                        mail_account_id: form.mail_account_id.value,
                        to: to,
                        cc: document.getElementById('field-cc').value,
                        subject: subject,
                        body: body,
                        in_reply_to: document.getElementById('in_reply_to').value,
                        references: document.getElementById('references').value,
                    }),
                })
                    .then((r) => r.json())
                    .then((data) => {
                        if (data.draft_id) {
                            draftIdField.value = data.draft_id;
                        }
                        statusEl.textContent = 'Draft saved';
                    })
                    .catch(() => { statusEl.textContent = ''; });
            }

            ['field-to', 'field-cc', 'field-subject', 'field-body'].forEach((id) => {
                document.getElementById(id).addEventListener('input', scheduleSave);
            });

            // --- Contact autocomplete on To/Cc ---
            function setupAutocomplete(inputId) {
                const input = document.getElementById(inputId);
                const dropdown = input.parentElement.querySelector('.contact-dropdown');
                let debounce = null;

                input.addEventListener('input', function () {
                    clearTimeout(debounce);
                    const parts = input.value.split(',');
                    const current = parts[parts.length - 1].trim();

                    if (current.length < 2) {
                        dropdown.classList.add('hidden');
                        return;
                    }

                    debounce = setTimeout(() => {
                        fetch('{{ route('contacts.search') }}?q=' + encodeURIComponent(current), {
                            headers: { 'Accept': 'application/json' },
                        })
                            .then((r) => r.json())
                            .then((contacts) => {
                                if (!contacts.length) {
                                    dropdown.classList.add('hidden');
                                    return;
                                }

                                dropdown.innerHTML = '';
                                contacts.forEach((c) => {
                                    const item = document.createElement('button');
                                    item.type = 'button';
                                    item.textContent = c.name ? `${c.name} <${c.email}>` : c.email;
                                    item.addEventListener('click', () => {
                                        parts[parts.length - 1] = ' ' + c.email;
                                        input.value = parts.map((p) => p.trim()).join(', ');
                                        dropdown.classList.add('hidden');
                                        input.focus();
                                        scheduleSave();
                                    });
                                    dropdown.appendChild(item);
                                });
                                dropdown.classList.remove('hidden');
                            })
                            .catch(() => {});
                    }, 200);
                });

                document.addEventListener('click', (e) => {
                    if (!input.parentElement.contains(e.target)) {
                        dropdown.classList.add('hidden');
                    }
                });
            }

            setupAutocomplete('field-to');
            setupAutocomplete('field-cc');

            // --- Attachment file list with remove ---
            (function () {
                const input = document.getElementById('attachment-input');
                const list = document.getElementById('attachment-list');
                let files = [];

                function fmt(bytes) {
                    if (bytes < 1024) return bytes + ' B';
                    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
                    return (bytes / 1048576).toFixed(1) + ' MB';
                }

                function render() {
                    list.innerHTML = '';
                    const dt = new DataTransfer();
                    files.forEach((file, i) => {
                        dt.items.add(file);
                        const li = document.createElement('li');
                        li.innerHTML = `<span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:16rem;">${file.name}</span><span style="color:var(--text-faint); flex-shrink:0;">${fmt(file.size)}</span>`;
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.style.cssText = 'color:var(--text-faint); flex-shrink:0; background:none; border:none;';
                        btn.innerHTML = '&times;';
                        btn.addEventListener('click', () => { files.splice(i, 1); render(); });
                        li.appendChild(btn);
                        list.appendChild(li);
                    });
                    input.files = dt.files;
                }

                input.addEventListener('change', () => {
                    files = [...files, ...Array.from(input.files)];
                    render();
                });
            })();
        })();
    </script>
@endsection
