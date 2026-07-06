<?php

/**
 * Feature toggles for zero.
 *
 * Each key is a feature name; each value is a boolean that can be overridden
 * via an environment variable of the same name, uppercased and prefixed with
 * FEATURE_ (e.g. FEATURE_IMAP_IDLE=true).
 *
 * Usage in code:
 *   if (config('features.imap_idle')) { ... }
 *
 * Usage in Blade:
 *
 *   @if(config('features.triage_view')) ... @endif
 *
 * ## Strategy
 *
 * Features default to on in production (true) or off (false) as appropriate.
 * Tests override via .env.testing or putenv() in setUp(). No database or
 * caching layer is involved — config values are resolved at boot time.
 *
 * A feature flag should be removed (and the old code path deleted) once the
 * feature has shipped and been stable for at least one release cycle.
 */
return [

    // IMAP IDLE push-delivery. Requires a launchd agent per account.
    // Disable to fall back to polling-only mode (mail:sync every 5 min).
    'imap_idle' => (bool) env('FEATURE_IMAP_IDLE', true),

    // Triage view (/triage) — one-at-a-time inbox-zero flow.
    'triage_view' => (bool) env('FEATURE_TRIAGE_VIEW', true),

    // Real-time inbox updates via Reverb WebSockets + Echo.
    // Disable if Reverb is not running (falls back to API polling every 30s).
    'realtime_inbox' => (bool) env('FEATURE_REALTIME_INBOX', true),

    // macOS system notifications on new mail (osascript). No-op on Linux/Windows.
    'macos_notifications' => (bool) env('FEATURE_MACOS_NOTIFICATIONS', true),

    // Draft auto-save in the compose view.
    'draft_autosave' => (bool) env('FEATURE_DRAFT_AUTOSAVE', true),

];
