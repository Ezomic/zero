<div id="outgoing-stack" class="outgoing-stack" role="status" aria-live="polite"></div>

<script>
    (function () {
        const stack = document.getElementById('outgoing-stack');
        const csrf = document.querySelector('meta[name="csrf-token"]').content;
        const indexUrl = @json(route('outgoing.index'));
        const items = new Map();
        let pollTimer = null;
        let tickTimer = null;

        function request(url, method) {
            return fetch(url, {
                method: method,
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            });
        }

        function el(tag, className, text) {
            const node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        }

        function label(item) {
            if (item.status === 'held') {
                if (item.delayed) return 'Still waiting to send. Is the queue worker running?';
                return item.left > 0 ? 'Sending in ' + item.left + 's' : 'Sending';
            }
            if (item.status === 'sending') return 'Sending';
            if (item.status === 'sent') return 'Sent';
            return 'Not sent';
        }

        function shape(item) {
            return [item.status, item.delayed, item.left > 0, item.note || ''].join('|');
        }

        function build(item) {
            const card = el('div', 'outgoing-item ' + (item.status === 'failed' || item.delayed ? 'is-failed' : item.status === 'sent' ? 'is-sent' : ''));
            card.dataset.id = item.id;
            card.dataset.status = item.status;

            const text = el('div', 'outgoing-text');
            item.titleEl = el('p', 'outgoing-title', label(item));
            text.append(item.titleEl);
            text.append(el('p', 'outgoing-sub', item.status === 'failed' && item.error ? item.error : (item.subject + ' \u00b7 ' + item.to)));
            if (item.note) text.append(el('p', 'outgoing-sub', item.note));
            card.append(text);

            const actions = el('div', 'outgoing-actions');

            if (item.status === 'held' && (item.left > 0 || item.delayed)) {
                const undo = el('button', 'btn sm primary', 'Undo');
                undo.type = 'button';
                undo.addEventListener('click', () => undoSend(item, undo));
                actions.append(undo);
            }

            if (item.status === 'failed') {
                const reopen = el('a', 'btn sm primary', 'Reopen');
                reopen.href = item.restore_url;
                actions.append(reopen);

                const dismiss = el('button', 'btn sm ghost', 'Dismiss');
                dismiss.type = 'button';
                dismiss.addEventListener('click', () => {
                    request(item.dismiss_url, 'DELETE').finally(() => { items.delete(item.id); render(); });
                });
                actions.append(dismiss);
            }

            card.append(actions);
            item.cardEl = card;
            item.shape = shape(item);

            return card;
        }

        // Cards are only rebuilt when what they show changes. Rebuilding on
        // every tick would swap the Undo button out from under a click in
        // progress.
        function render() {
            const cards = [];

            items.forEach((item) => {
                cards.push(item.cardEl && item.shape === shape(item) ? item.cardEl : build(item));
            });

            stack.replaceChildren(...cards);
        }

        function undoSend(item, button) {
            button.disabled = true;
            request(item.undo_url, 'POST')
                .then((response) => response.json().then((body) => ({ ok: response.ok, body: body })))
                .then(({ ok, body }) => {
                    if (ok) {
                        window.location.href = body.restore_url;
                        return;
                    }
                    item.note = body.message || 'Too late to undo.';
                    item.left = 0;
                    render();
                    poll();
                })
                .catch(() => { button.disabled = false; });
        }

        function absorb(messages) {
            const seen = new Set();

            messages.forEach((message) => {
                seen.add(message.id);
                const known = items.get(message.id);
                items.set(message.id, Object.assign({}, known, message, {
                    deadline: Date.now() + message.seconds_left * 1000,
                    left: message.seconds_left,
                    note: known ? known.note : undefined,
                }));
            });

            items.forEach((_, id) => { if (!seen.has(id)) items.delete(id); });
            render();
            schedule();
        }

        function poll() {
            fetch(indexUrl, { headers: { 'Accept': 'application/json' } })
                .then((response) => response.json())
                .then((data) => absorb(data.messages))
                .catch(() => schedule());
        }

        function inFlight() {
            return Array.from(items.values()).some((item) => item.status === 'held' || item.status === 'sending');
        }

        function schedule() {
            clearTimeout(pollTimer);
            clearInterval(tickTimer);

            if (!inFlight()) return;

            pollTimer = setTimeout(poll, 2000);
            tickTimer = setInterval(() => {
                items.forEach((item) => {
                    if (item.status === 'held') item.left = Math.max(0, Math.ceil((item.deadline - Date.now()) / 1000));
                    if (item.titleEl) item.titleEl.textContent = label(item);
                });
                render();
            }, 500);
        }

        absorb(@json($messages));
    })();
</script>
