<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Thrown from inside ImapSyncService's full-sync batch loop once a run has
 * used up its soft time budget. Caught in sync() and treated as a clean,
 * intentional pause: progress is already checkpointed per batch, the account
 * stays in `syncing` status, and the next scheduled run resumes where this
 * one left off. Never logged as an error or surfaced to the user.
 */
class SyncBudgetExceededException extends Exception {}
