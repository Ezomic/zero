<?php

namespace Tests\Unit;

use App\Support\MimeHeader;
use PHPUnit\Framework\TestCase;

class MimeHeaderTest extends TestCase
{
    public function test_it_decodes_a_base64_utf8_encoded_word(): void
    {
        $this->assertSame(
            'Here’s what to watch next',
            MimeHeader::decode('=?UTF-8?B?SGVyZeKAmXMgd2hhdCB0byB3YXRjaCBuZXh0?='),
        );
    }

    public function test_it_decodes_a_q_encoded_word(): void
    {
        $this->assertSame('Café', MimeHeader::decode('=?UTF-8?Q?Caf=C3=A9?='));
    }

    public function test_it_decodes_a_run_of_multiple_encoded_words(): void
    {
        $this->assertSame(
            'Beveiligingsmelding café',
            MimeHeader::decode('=?UTF-8?Q?Beveiligingsmelding?= =?UTF-8?B?IGNhZsOp?='),
        );
    }

    public function test_it_leaves_plain_text_untouched(): void
    {
        $this->assertSame('Just a normal subject', MimeHeader::decode('Just a normal subject'));
    }

    public function test_it_passes_null_through(): void
    {
        $this->assertNull(MimeHeader::decode(null));
    }
}
