<?php

return [

    // Seconds a sent message is held before it leaves, during which it can be
    // taken back. 0 sends as soon as a worker picks it up and offers no undo.
    // Clamped to 0..max_hold_seconds wherever it is read.
    'hold_seconds' => (int) env('OUTGOING_HOLD_SECONDS', 10),

    'max_hold_seconds' => 120,

    // Private disk (not reachable by URL) holding the attachments of messages
    // that are held, failed or taken back.
    'disk' => 'outgoing',

    'max_attachments' => 20,

    // Gmail and Outlook both refuse anything larger than this.
    'max_total_attachment_kb' => 25 * 1024,

    // How long a message that was taken back, or failed, keeps its attachments
    // so the composer can be reopened with them.
    'restore_ttl_hours' => 24,

    // A held message whose job has not run this long after its time was
    // probably lost (queue table cleared, worker down); the sweep queues it
    // again. Safe, since the job claims the row before sending.
    'redispatch_after_seconds' => 120,

    // A message stuck in "sending" this long belongs to a worker that died
    // mid-send. Whether it was delivered is unknowable, so it is marked failed
    // with that said, instead of being sent again.
    'stale_sending_minutes' => 10,
];
