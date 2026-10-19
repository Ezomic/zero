<?php

namespace App\Http\Requests;

use App\Models\OutgoingMessage;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Symfony\Component\Mime\Address;
use Throwable;

class SendMessageRequest extends FormRequest
{
    private ?OutgoingMessage $restoredFrom = null;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mail_account_id' => ['required', Rule::exists('mail_accounts', 'id')->where('user_id', $this->user()?->id)],
            'to' => ['required', 'string'],
            'cc' => ['nullable', 'string'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'in_reply_to' => ['nullable', 'string'],
            'references' => ['nullable', 'string'],
            'draft_id' => ['nullable', 'integer'],
            'attachments' => ['nullable', 'array', 'max:'.config()->integer('outgoing.max_attachments')],
            'attachments.*' => ['file', 'max:10240'],
            'restore_id' => ['nullable', 'integer'],
            'keep_attachments' => ['nullable', 'array'],
            'keep_attachments.*' => ['integer', 'min:0'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->checkRecipients($validator, 'to', required: true);
            $this->checkRecipients($validator, 'cc', required: false);
            $this->resolveRestore($validator);
            $this->checkTotalSize($validator);
        }];
    }

    /** @return array{to: list<string>, cc: list<string>, subject: string, body: string, in_reply_to: ?string, references: ?string} */
    public function fields(): array
    {
        return [
            'to' => $this->addresses('to'),
            'cc' => $this->addresses('cc'),
            'subject' => $this->string('subject')->toString(),
            'body' => $this->string('body')->toString(),
            'in_reply_to' => $this->string('in_reply_to')->toString() ?: null,
            'references' => $this->string('references')->toString() ?: null,
        ];
    }

    /** @return list<UploadedFile> */
    public function uploads(): array
    {
        $files = $this->file('attachments', []);

        return array_values(is_array($files) ? $files : []);
    }

    public function restoredFrom(): ?OutgoingMessage
    {
        return $this->restoredFrom;
    }

    /** @return list<int> */
    public function keptAttachments(): array
    {
        return array_values(array_map(fn (mixed $position): int => is_numeric($position) ? (int) $position : -1, $this->array('keep_attachments')));
    }

    public function draftId(): ?int
    {
        return $this->filled('draft_id') ? $this->integer('draft_id') : null;
    }

    /** @return list<string> */
    protected function addresses(string $key): array
    {
        $addresses = collect(explode(',', $this->string($key)->toString()))
            ->map(fn (string $address): string => trim($address))
            ->filter();

        return array_values($addresses->all());
    }

    protected function checkRecipients(Validator $validator, string $key, bool $required): void
    {
        $addresses = $this->addresses($key);

        if ($required && $addresses === []) {
            $validator->errors()->add($key, 'Add at least one recipient.');

            return;
        }

        foreach ($addresses as $address) {
            try {
                Address::create($address);
            } catch (Throwable) {
                $validator->errors()->add($key, "\"{$address}\" is not a valid email address.");

                return;
            }
        }
    }

    protected function resolveRestore(Validator $validator): void
    {
        if (! $this->filled('restore_id')) {
            return;
        }

        $user = $this->user();

        $source = $user instanceof User
            ? $user->outgoingMessages()->whereIn('status', [OutgoingMessage::CANCELLED, OutgoingMessage::FAILED])->find($this->integer('restore_id'))
            : null;

        if ($source === null) {
            $validator->errors()->add('restore_id', 'That message is no longer available to reopen. Attach the files again.');

            return;
        }

        $this->restoredFrom = $source;
    }

    protected function checkTotalSize(Validator $validator): void
    {
        $bytes = array_sum(array_map(fn (UploadedFile $file): int => (int) $file->getSize(), $this->uploads()));

        if ($this->restoredFrom !== null) {
            foreach ($this->restoredFrom->attachments as $position => $attachment) {
                if (in_array($position, $this->keptAttachments(), true)) {
                    $bytes += $attachment['size'];
                }
            }
        }

        $limitKb = config()->integer('outgoing.max_total_attachment_kb');

        if ($bytes > $limitKb * 1024) {
            $validator->errors()->add('attachments', 'The attachments together are larger than '.intdiv($limitKb, 1024).' MB.');
        }
    }
}
