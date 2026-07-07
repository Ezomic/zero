<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Thrown from inside IdleMailboxCommand's IDLE callback to break out of
 * Folder::idle()'s internal while(true) loop when the watched account has
 * been deleted or deactivated mid-session. Never expected to reach the
 * user or be logged as an error — it's caught immediately around the idle()
 * call and treated as a clean, intentional stop.
 */
class AccountWatchStoppedException extends Exception {}
