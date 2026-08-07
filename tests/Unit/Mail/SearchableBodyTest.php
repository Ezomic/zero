<?php

namespace Tests\Unit\Mail;

use App\Support\SearchableBody;
use Tests\TestCase;

class SearchableBodyTest extends TestCase
{
    public function test_it_reduces_html_to_the_words_in_it(): void
    {
        $text = SearchableBody::fromHtml('<p>The <b>mooring</b> mast needs inspecting.</p>');

        $this->assertSame('The mooring mast needs inspecting.', $text);
    }

    /**
     * Tags have to become whitespace, not vanish, or words either side fuse
     * into a token nobody will ever search for.
     */
    public function test_adjacent_tags_do_not_fuse_words(): void
    {
        $text = SearchableBody::fromHtml('<td>Amsterdam</td><td>Rotterdam</td>');

        $this->assertSame('Amsterdam Rotterdam', $text);
    }

    public function test_script_and_style_contents_are_dropped(): void
    {
        $text = SearchableBody::fromHtml(
            '<style>.x{color:red}</style><script>var secret = 1;</script><p>Real words</p>'
        );

        $this->assertSame('Real words', $text);
    }

    public function test_entities_are_decoded(): void
    {
        $text = SearchableBody::fromHtml('<p>Ben &amp; Jerry&rsquo;s caf&eacute;</p>');

        $this->assertStringContainsString('Ben & Jerry', (string) $text);
        $this->assertStringContainsString('café', (string) $text);
    }

    public function test_whitespace_is_collapsed(): void
    {
        $text = SearchableBody::fromHtml("<p>one</p>\n\n\n   <p>two</p>");

        $this->assertSame('one two', $text);
    }

    public function test_an_html_body_with_no_words_yields_nothing(): void
    {
        $this->assertNull(SearchableBody::fromHtml('<div><span></span></div>'));
        $this->assertNull(SearchableBody::fromHtml(''));
        $this->assertNull(SearchableBody::fromHtml(null));
    }

    public function test_a_real_text_part_always_wins(): void
    {
        $stored = SearchableBody::forStorage('the plain part', '<p>the html part</p>');

        $this->assertSame('the plain part', $stored);
    }

    public function test_html_is_only_a_fallback(): void
    {
        $this->assertSame('the html part', SearchableBody::forStorage(null, '<p>the html part</p>'));
        $this->assertSame('the html part', SearchableBody::forStorage('   ', '<p>the html part</p>'));
    }

    public function test_nothing_in_means_nothing_out(): void
    {
        $this->assertNull(SearchableBody::forStorage(null, null));
    }

    public function test_a_runaway_body_is_bounded(): void
    {
        $text = SearchableBody::fromHtml('<p>'.str_repeat('word ', 50_000).'</p>');

        $this->assertNotNull($text);
        $this->assertLessThanOrEqual(100_000, mb_strlen($text));
    }

    public function test_multibyte_content_survives(): void
    {
        $text = SearchableBody::fromHtml('<p>Zürich naïve 日本語</p>');

        $this->assertSame('Zürich naïve 日本語', $text);
    }
}
