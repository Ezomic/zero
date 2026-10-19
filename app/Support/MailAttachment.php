<?php

namespace App\Support;

final readonly class MailAttachment
{
    public function __construct(
        public string $name,
        public string $mimeType,
        public string $contents,
    ) {}
}
