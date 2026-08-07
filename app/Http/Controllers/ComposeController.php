<?php

namespace App\Http\Controllers;

use App\Concerns\InteractsWithCurrentUser;
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
    use InteractsWithCurrentUser;

    public function create(Request $request): View
    {
        $accounts = $this->currentUser()->mailAccounts()->where('is_active', true)->get();
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
        $validated = $request->validate([
            'mail_account_id' => ['required', $this->ownedAccountRule()],
            'to' => ['required', 'string'], // comma-separated
            'cc' => ['nullable', 'string'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'in_reply_to' => ['nullable', 'string'],
            'references' => ['nullable', 'string'],
            'draft_id' => ['nullable', 'integer'],
            'attachments.*' => ['nullable', 'file', 'max:10240'],
        ]);

        $data = [];

        foreach (is_array($validated) ? $validated : [] as $key => $value) {
            $data[(string) $key] = $value;
        }

        /** @var MailAccount $account */
        $account = MailAccount::findOrFail($data['mail_account_id']);
        abort_unless($account->user_id === auth()->id(), 403);

        $toAddresses = $this->splitAddresses($request->string('to')->toString());
        $ccAddresses = $this->splitAddresses($request->string('cc')->toString());

        $sender->send($account, [
            'to' => $toAddresses,
            'cc' => $ccAddresses,
            'subject' => $request->string('subject')->toString(),
            'html' => nl2br(e($request->string('body')->toString())),
            'in_reply_to' => $request->string('in_reply_to')->toString() ?: null,
            'references' => $request->string('references')->toString() ?: null,
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
        abort_unless($email->mailAccount?->user_id === auth()->id(), 403);

        $accounts = $this->currentUser()->mailAccounts()->where('is_active', true)->get();
        $account = $email->requireMailAccount();
        $originalText = strip_tags($email->body_text ?: $email->body_html ?: '');
        $subject = $email->subject ?? '';

        $prefill = $this->emptyPrefill();
        $prefill['mail_account_id'] = $account->id;
        $prefill['subject'] = $subject;

        if ($mode === 'forward') {
            $prefill['subject'] = preg_match('/^fwd:/i', $subject) ? $subject : "Fwd: {$subject}";
            $prefill['body'] = "\n\n---------- Forwarded message ----------\n"
                .'From: '.($email->from_name ?: $email->from_address)." <{$email->from_address}>\n"
                .'Date: '.($email->sent_at?->format('M j, Y g:i A') ?? '')."\n"
                ."Subject: {$subject}\n\n"
                .$originalText;

            return view('inbox.compose', compact('accounts', 'prefill'));
        }

        $prefill['subject'] = preg_match('/^re:/i', $subject) ? $subject : "Re: {$subject}";
        $prefill['to'] = $email->from_address;
        $prefill['in_reply_to'] = $email->message_id;
        $prefill['references'] = trim(($email->references_header ? $email->references_header.' ' : '').$email->message_id);

        $quotedHeader = sprintf(
            'On %s, %s wrote:',
            $email->sent_at?->format('M j, Y \a\t g:i A') ?? '',
            $email->from_name ?: $email->from_address
        );
        $quoted = collect(explode("\n", $originalText))->map(fn (string $line): string => "> {$line}")->implode("\n");
        $prefill['body'] = "\n\n{$quotedHeader}\n{$quoted}";

        if ($mode === 'reply-all') {
            $others = collect([...($email->to_addresses ?? []), ...($email->cc_addresses ?? [])])
                ->map(fn (mixed $formatted): ?string => $this->extractAddress(is_string($formatted) ? $formatted : ''))
                ->filter(fn ($addr) => $addr
                    && strcasecmp($addr, $account->email_address) !== 0
                    && strcasecmp($addr, $email->from_address ?? '') !== 0)
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
