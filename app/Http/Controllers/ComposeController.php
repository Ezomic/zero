<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Draft;
use App\Models\Email;
use App\Models\MailAccount;
use App\Services\Mail\MailSenderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ComposeController extends Controller
{
    public function create(Request $request): View
    {
        $accounts = auth()->user()->mailAccounts()->where('is_active', true)->get();
        $prefill = $this->emptyPrefill();

        if ($request->filled('draft')) {
            $draft = Draft::where('user_id', auth()->id())->findOrFail($request->integer('draft'));

            $prefill = [
                'mail_account_id' => $draft->mail_account_id,
                'to' => $draft->to_addresses,
                'cc' => $draft->cc_addresses,
                'subject' => $draft->subject,
                'body' => $draft->body,
                'in_reply_to' => $draft->in_reply_to,
                'references' => $draft->references_header,
                'draft_id' => $draft->id,
            ];
        }

        return view('inbox.compose', compact('accounts', 'prefill'));
    }

    public function reply(Email $email): View
    {
        return $this->prefillFromEmail($email, 'reply');
    }

    public function replyAll(Email $email): View
    {
        return $this->prefillFromEmail($email, 'reply-all');
    }

    public function forward(Email $email): View
    {
        return $this->prefillFromEmail($email, 'forward');
    }

    public function store(Request $request, MailSenderService $sender): RedirectResponse
    {
        $data = $request->validate([
            'mail_account_id' => ['required', 'exists:mail_accounts,id'],
            'to' => ['required', 'string'], // comma-separated
            'cc' => ['nullable', 'string'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'in_reply_to' => ['nullable', 'string'],
            'references' => ['nullable', 'string'],
            'draft_id' => ['nullable', 'integer'],
            'attachments.*' => ['nullable', 'file', 'max:10240'],
        ]);

        /** @var MailAccount $account */
        $account = MailAccount::findOrFail($data['mail_account_id']);
        abort_unless($account->user_id === auth()->id(), 403);

        $toAddresses = $this->splitAddresses($data['to']);
        $ccAddresses = $this->splitAddresses($data['cc'] ?? '');

        $sender->send($account, [
            'to' => $toAddresses,
            'cc' => $ccAddresses,
            'subject' => $data['subject'],
            'html' => nl2br(e($data['body'])),
            'in_reply_to' => $data['in_reply_to'] ?? null,
            'references' => $data['references'] ?? null,
            'attachments' => array_values(array_filter($request->file('attachments', []))),
        ]);

        foreach ([...$toAddresses, ...$ccAddresses] as $address) {
            Contact::remember(auth()->id(), $address);
        }

        if (! empty($data['draft_id'])) {
            Draft::where('user_id', auth()->id())->where('id', $data['draft_id'])->delete();
        }

        return redirect()->route('inbox.index')->with('status', 'Email sent.');
    }

    protected function prefillFromEmail(Email $email, string $mode): View
    {
        abort_unless($email->mailAccount->user_id === auth()->id(), 403);

        $accounts = auth()->user()->mailAccounts()->where('is_active', true)->get();
        $account = $email->mailAccount;
        $originalText = strip_tags($email->body_text ?: $email->body_html ?: '');

        $prefill = $this->emptyPrefill();
        $prefill['mail_account_id'] = $account->id;
        $prefill['subject'] = $email->subject;

        if ($mode === 'forward') {
            $prefill['subject'] = preg_match('/^fwd:/i', $email->subject) ? $email->subject : "Fwd: {$email->subject}";
            $prefill['body'] = "\n\n---------- Forwarded message ----------\n"
                .'From: '.($email->from_name ?: $email->from_address)." <{$email->from_address}>\n"
                .'Date: '.optional($email->sent_at)->format('M j, Y g:i A')."\n"
                ."Subject: {$email->subject}\n\n"
                .$originalText;

            return view('inbox.compose', compact('accounts', 'prefill'));
        }

        $prefill['subject'] = preg_match('/^re:/i', $email->subject) ? $email->subject : "Re: {$email->subject}";
        $prefill['to'] = $email->from_address;
        $prefill['in_reply_to'] = $email->message_id;
        $prefill['references'] = trim(($email->references_header ? $email->references_header.' ' : '').$email->message_id);

        $quotedHeader = sprintf(
            'On %s, %s wrote:',
            optional($email->sent_at)->format('M j, Y \a\t g:i A'),
            $email->from_name ?: $email->from_address
        );
        $quoted = collect(explode("\n", $originalText))->map(fn ($line) => "> {$line}")->implode("\n");
        $prefill['body'] = "\n\n{$quotedHeader}\n{$quoted}";

        if ($mode === 'reply-all') {
            $others = collect([...($email->to_addresses ?? []), ...($email->cc_addresses ?? [])])
                ->map(fn ($formatted) => $this->extractAddress($formatted))
                ->filter(fn ($addr) => $addr
                    && strcasecmp($addr, $account->email_address) !== 0
                    && strcasecmp($addr, $email->from_address) !== 0)
                ->unique()
                ->values();

            $prefill['cc'] = $others->implode(', ');
        }

        return view('inbox.compose', compact('accounts', 'prefill'));
    }

    protected function extractAddress(string $formatted): ?string
    {
        if (preg_match('/<([^>]+)>/', $formatted, $m)) {
            return $m[1];
        }

        return trim($formatted) ?: null;
    }

    /** @return array<string, mixed> */
    protected function emptyPrefill(): array
    {
        return [
            'mail_account_id' => null,
            'to' => '',
            'cc' => '',
            'subject' => '',
            'body' => '',
            'in_reply_to' => null,
            'references' => null,
            'draft_id' => null,
        ];
    }

    /** @return array<int, string> */
    protected function splitAddresses(string $raw): array
    {
        return collect(explode(',', $raw))
            ->map(fn ($a) => trim($a))
            ->filter()
            ->values()
            ->all();
    }
}
