<?php

namespace Tests\Unit\Mail;

use App\Models\MailRule;
use App\Support\MailRules\MessageFacts;
use App\Support\MailRules\RuleMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailRuleMatcherTest extends TestCase
{
    /** @param array<string, mixed> $attributes */
    private function rule(array $attributes): MailRule
    {
        return (new MailRule)->forceFill($attributes + [
            'field' => 'from',
            'operator' => 'contains',
            'action' => 'archive',
            'position' => 1,
            'stop_on_match' => false,
        ]);
    }

    /** @param list<string> $recipients */
    private function facts(?string $from = 'sender@example.com', string $subject = 'Hello', array $recipients = ['me@example.com'], ?string $name = null): MessageFacts
    {
        return new MessageFacts(fromAddress: $from, fromName: $name, recipients: $recipients, subject: $subject);
    }

    public function test_contains_is_a_plain_case_insensitive_substring(): void
    {
        $this->assertTrue(RuleMatcher::matches($this->rule(['value' => 'NEWS']), $this->facts('Newsletter@Example.com')));
        $this->assertFalse(RuleMatcher::matches($this->rule(['value' => 'billing']), $this->facts('newsletter@example.com')));
    }

    /** @return array<string, array{string, string}> */
    public static function regexLikePatterns(): array
    {
        return [
            'dot star' => ['a.*c', 'abc'],
            'catastrophic' => ['(a+)+$', str_repeat('a', 5000).'!'],
            'anchors' => ['^hello$', 'hello'],
            'brackets' => ['[abc]', 'a'],
            'delimited' => ['/hello/i', 'hello'],
        ];
    }

    #[DataProvider('regexLikePatterns')]
    public function test_regex_like_patterns_are_literal_text(string $pattern, string $subject): void
    {
        $rule = $this->rule(['field' => 'subject', 'value' => $pattern]);

        $this->assertFalse(RuleMatcher::matches($rule, $this->facts(subject: $subject)));
        $this->assertTrue(RuleMatcher::matches($rule, $this->facts(subject: "x {$pattern} y")));
    }

    public function test_an_empty_or_whitespace_pattern_never_matches(): void
    {
        foreach (['', '   ', "\t\n", "\u{00A0}"] as $value) {
            $this->assertFalse(RuleMatcher::matches($this->rule(['field' => 'subject', 'value' => $value]), $this->facts(subject: 'anything')), json_encode($value) ?: '');
        }
    }

    public function test_a_huge_pattern_matches_nothing_and_does_not_hang(): void
    {
        $rule = $this->rule(['field' => 'subject', 'value' => str_repeat('a', 100_000)]);

        $this->assertFalse(RuleMatcher::matches($rule, $this->facts(subject: str_repeat('a', 99_999))));
    }

    public function test_case_folding_is_unicode_aware(): void
    {
        $subject = $this->rule(['field' => 'subject', 'value' => 'STRASSE']);
        $this->assertTrue(RuleMatcher::matches($subject, $this->facts(subject: 'Die Straße')));

        $accent = $this->rule(['field' => 'subject', 'value' => 'ÉCOLE']);
        $this->assertTrue(RuleMatcher::matches($accent, $this->facts(subject: 'ecole? non: école')));
    }

    public function test_composed_and_decomposed_accents_match_each_other(): void
    {
        $composed = $this->rule(['field' => 'subject', 'value' => "caf\u{00E9}"]);

        $this->assertTrue(RuleMatcher::matches($composed, $this->facts(subject: "Cafe\u{0301} menu")));
    }

    public function test_invalid_utf8_does_not_throw(): void
    {
        $rule = $this->rule(['field' => 'subject', 'value' => 'invoice']);

        $this->assertTrue(RuleMatcher::matches($rule, $this->facts(subject: "Invoice \xC3\x28 \xFF 2026")));
        $this->assertFalse(RuleMatcher::matches($rule, $this->facts(subject: "\xFF\xFE")));
    }

    public function test_sender_contains_also_matches_the_display_name(): void
    {
        $rule = $this->rule(['value' => 'paypal']);

        $this->assertTrue(RuleMatcher::matches($rule, $this->facts('x@mailer.test', name: 'PayPal Service')));
    }

    public function test_is_compares_the_whole_address(): void
    {
        $rule = $this->rule(['operator' => 'is', 'value' => 'noreply@noisy.test']);

        $this->assertTrue(RuleMatcher::matches($rule, $this->facts('NoReply@Noisy.test')));
        $this->assertFalse(RuleMatcher::matches($rule, $this->facts('xnoreply@noisy.test')));
        $this->assertFalse(RuleMatcher::matches($rule, $this->facts('noreply@noisy.test.evil.test')));
    }

    public function test_domain_is_exact(): void
    {
        $rule = $this->rule(['operator' => 'domain', 'value' => 'noisy.test']);

        $this->assertTrue(RuleMatcher::matches($rule, $this->facts('a@noisy.test')));
        $this->assertFalse(RuleMatcher::matches($rule, $this->facts('a@mail.noisy.test')));
        $this->assertFalse(RuleMatcher::matches($rule, $this->facts('a@notnoisy.test')));
    }

    public function test_a_sender_that_is_missing_or_has_no_at_sign_matches_no_sender_rule(): void
    {
        foreach (['contains', 'is', 'domain'] as $operator) {
            $rule = $this->rule(['operator' => $operator, 'value' => 'noisy.test']);

            $this->assertFalse(RuleMatcher::matches($rule, $this->facts(null)), $operator);
            $this->assertFalse(RuleMatcher::matches($rule, $this->facts('')), $operator);
        }

        $this->assertFalse(RuleMatcher::matches($this->rule(['operator' => 'domain', 'value' => 'noisy.test']), $this->facts('noisy.test')));
    }

    public function test_recipient_rules_read_the_address_out_of_name_and_angle_form(): void
    {
        $rule = $this->rule(['field' => 'to', 'operator' => 'is', 'value' => 'list@club.test']);

        $this->assertTrue(RuleMatcher::matches($rule, $this->facts(recipients: ['Club List <LIST@club.test>'])));
        $this->assertTrue(RuleMatcher::matches($rule, $this->facts(recipients: ['other@x.test', 'list@club.test'])));
        $this->assertFalse(RuleMatcher::matches($rule, $this->facts(recipients: ['Club List <list@club.test.evil>'])));
        $this->assertFalse(RuleMatcher::matches($rule, $this->facts(recipients: [])));
    }

    public function test_subject_rules_have_no_domain_operator(): void
    {
        $rule = $this->rule(['field' => 'subject', 'operator' => 'domain', 'value' => 'hello']);

        $this->assertFalse(RuleMatcher::matches($rule, $this->facts(subject: 'hello')));
    }

    public function test_an_unknown_field_or_operator_matches_nothing(): void
    {
        $this->assertFalse(RuleMatcher::matches($this->rule(['field' => 'body', 'value' => 'hello']), $this->facts(subject: 'hello')));
        $this->assertFalse(RuleMatcher::matches($this->rule(['operator' => 'regex', 'value' => 'e']), $this->facts()));
    }

    public function test_all_matching_rules_apply_in_order_and_the_first_folder_wins(): void
    {
        $rules = [
            $this->rule(['id' => 1, 'value' => 'shop', 'action' => 'file', 'folder' => 'Shops']),
            $this->rule(['id' => 2, 'value' => 'shop', 'action' => 'star']),
            $this->rule(['id' => 3, 'value' => 'shop', 'action' => 'file', 'folder' => 'Later']),
            $this->rule(['id' => 4, 'value' => 'shop', 'action' => 'mark_read']),
            $this->rule(['id' => 5, 'value' => 'shop', 'action' => 'archive']),
            $this->rule(['id' => 6, 'value' => 'nomatch', 'action' => 'archive']),
        ];

        $outcome = RuleMatcher::evaluate($rules, $this->facts('a@shop.test'));

        $this->assertSame('Shops', $outcome->folder);
        $this->assertTrue($outcome->star);
        $this->assertTrue($outcome->markRead);
        $this->assertSame(5, $outcome->archivedByRuleId);
        $this->assertSame([1, 2, 3, 4, 5], $outcome->matchedRuleIds);
    }

    public function test_stop_on_match_ends_evaluation(): void
    {
        $rules = [
            $this->rule(['id' => 1, 'value' => 'shop', 'action' => 'star', 'stop_on_match' => true]),
            $this->rule(['id' => 2, 'value' => 'shop', 'action' => 'archive']),
        ];

        $outcome = RuleMatcher::evaluate($rules, $this->facts('a@shop.test'));

        $this->assertTrue($outcome->star);
        $this->assertNull($outcome->archivedByRuleId);
    }

    public function test_a_stop_rule_that_does_not_match_does_not_stop(): void
    {
        $rules = [
            $this->rule(['id' => 1, 'value' => 'zzz', 'action' => 'star', 'stop_on_match' => true]),
            $this->rule(['id' => 2, 'value' => 'shop', 'action' => 'archive']),
        ];

        $this->assertSame(2, RuleMatcher::evaluate($rules, $this->facts('a@shop.test'))->archivedByRuleId);
    }

    public function test_a_rule_that_throws_is_skipped_and_the_rest_still_apply(): void
    {
        $broken = new class extends MailRule
        {
            public function getAttribute($key): mixed
            {
                if ($key === 'value') {
                    throw new \RuntimeException('boom');
                }

                return parent::getAttribute($key);
            }
        };
        $broken->forceFill(['id' => 1, 'field' => 'from', 'operator' => 'contains', 'action' => 'star']);
        $rules = [$broken, $this->rule(['id' => 2, 'value' => 'shop', 'action' => 'archive'])];

        $outcome = RuleMatcher::evaluate($rules, $this->facts('a@shop.test'));

        $this->assertFalse($outcome->star);
        $this->assertSame(2, $outcome->archivedByRuleId);
    }

    public function test_no_matching_rule_is_a_harmless_empty_outcome(): void
    {
        $outcome = RuleMatcher::evaluate([$this->rule(['id' => 1, 'value' => 'zzz'])], $this->facts());

        $this->assertTrue($outcome->isEmpty());
    }
}
