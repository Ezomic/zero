<?php

use App\Http\Controllers\Auth\GoogleOAuthController;
use App\Http\Controllers\Auth\MicrosoftOAuthController;
use App\Http\Controllers\ComposeController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DraftController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\MailAccountController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\TriageController;
use Illuminate\Support\Facades\Route;

// Assumes Laravel Breeze (or similar) already provides /login, /register, etc.
// and the 'auth' middleware group below.

Route::middleware(['auth'])->group(function () {
    Route::get('/', [InboxController::class, 'index'])->name('inbox.index');

    Route::get('/triage', [TriageController::class, 'index'])->name('triage.index');
    Route::post('/triage/{email}/delete', [TriageController::class, 'delete'])->name('triage.delete');
    Route::post('/triage/{email}/move', [TriageController::class, 'move'])->name('triage.move');
    Route::post('/triage/{email}/skip', [TriageController::class, 'skip'])->name('triage.skip');
    Route::post('/triage/reset-skipped', [TriageController::class, 'resetSkipped'])->name('triage.resetSkipped');

    // Must precede /emails/{email} so "ref" isn't bound as an email id.
    Route::get('/emails/ref/{ulid}', [InboxController::class, 'showByRef'])->name('inbox.showByUlid');
    Route::get('/emails/{email}', [InboxController::class, 'show'])->name('inbox.show');
    Route::get('/emails/{email}/panel', [InboxController::class, 'panel'])->name('inbox.panel');
    Route::post('/emails/{email}/archive', [InboxController::class, 'archive'])->name('inbox.archive');
    Route::post('/emails/{email}/unarchive', [InboxController::class, 'unarchive'])->name('inbox.unarchive');
    Route::post('/emails/{email}/mark-unread', [InboxController::class, 'markUnread'])->name('inbox.markUnread');
    Route::post('/emails/{email}/move', [InboxController::class, 'move'])->name('inbox.move');
    Route::delete('/emails/{email}', [InboxController::class, 'destroy'])->name('inbox.destroy');
    Route::post('/emails/bulk', [InboxController::class, 'bulk'])->name('inbox.bulk');
    Route::get('/api/unread-count', [InboxController::class, 'unreadCount'])->name('inbox.unreadCount');
    Route::get('/api/new-emails', [InboxController::class, 'newEmails'])->name('inbox.newEmails');

    Route::get('/emails/{email}/reply', [ComposeController::class, 'reply'])->name('compose.reply');
    Route::get('/emails/{email}/reply-all', [ComposeController::class, 'replyAll'])->name('compose.replyAll');
    Route::get('/emails/{email}/forward', [ComposeController::class, 'forward'])->name('compose.forward');

    Route::get('/compose', [ComposeController::class, 'create'])->name('compose.create');
    Route::post('/compose', [ComposeController::class, 'store'])->name('compose.store');

    Route::get('/drafts', [DraftController::class, 'index'])->name('drafts.index');
    Route::post('/drafts', [DraftController::class, 'autosave'])->name('drafts.autosave');
    Route::delete('/drafts/{draft}', [DraftController::class, 'destroy'])->name('drafts.destroy');

    Route::get('/contacts/search', [ContactController::class, 'search'])->name('contacts.search');

    Route::get('/accounts', [MailAccountController::class, 'index'])->name('accounts.index');
    Route::get('/accounts/create', [MailAccountController::class, 'create'])->name('accounts.create');
    Route::post('/accounts', [MailAccountController::class, 'store'])->name('accounts.store');
    Route::get('/accounts/{account}/edit', [MailAccountController::class, 'edit'])->name('accounts.edit');
    Route::put('/accounts/{account}', [MailAccountController::class, 'update'])->name('accounts.update');
    Route::delete('/accounts/{account}', [MailAccountController::class, 'destroy'])->name('accounts.destroy');
    Route::post('/accounts/{account}/sync', [MailAccountController::class, 'sync'])->name('accounts.sync');
    Route::post('/accounts/{account}/reenable', [MailAccountController::class, 'reenable'])->name('accounts.reenable');

    Route::get('/auth/google/redirect', [GoogleOAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleOAuthController::class, 'callback'])->name('auth.google.callback');

    Route::get('/auth/microsoft/redirect', [MicrosoftOAuthController::class, 'redirect'])->name('auth.microsoft.redirect');
    Route::get('/auth/microsoft/callback', [MicrosoftOAuthController::class, 'callback'])->name('auth.microsoft.callback');

    Route::get('/security', [SecurityController::class, 'show'])->name('security.show');
});

require __DIR__.'/auth.php';
