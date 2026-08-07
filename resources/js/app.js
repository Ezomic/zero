import Alpine from 'alpinejs';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Alpine = Alpine;
Alpine.start();

// Only wire up Reverb when the feature is on. The client used to be built
// unconditionally, so with the flag off, or with Reverb simply not running,
// every page load opened a websocket that could not connect and then retried
// on a backoff — console noise and reconnect traffic on exactly the host
// where realtime was deliberately turned off (ZERO-110). The sidebar badge
// and the new-mail list both poll independently, so nothing here is load
// bearing when it is absent.
if (window.zeroRealtimeInbox) {
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
