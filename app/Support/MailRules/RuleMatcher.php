<?php

namespace App\Support\MailRules;

use App\Models\MailRule;
use Illuminate\Support\Facades\Log;
use Normalizer;
use Throwable;

/**
 * Decides whether a rule matches a message, and what a set of rules does to
 * it.
 *
 * Patterns are plain text, never regular expressions. They come from a
 * settings form and run against attacker-controlled headers inside the sync,
 * so there is no pattern that can make a match slow, and a stray "(" cannot
 * break a rule.
 */
final class RuleMatcher
{
    /** An exact-subject rule can never match a subject longer than a pattern can be, so longer ones are not trimmed or folded. */
    private const MAX_SUBJECT_FOR_EXACT = MailRule::MAX_PATTERN * 8;

    /**
     * Case-insensitive in the Unicode sense, and indifferent to whether an
     * accent arrived composed or as a base letter plus a combining mark.
     * Invalid UTF-8 in a header is scrubbed rather than thrown on, and
     * invisible format characters (zero-width spaces, soft hyphens, direction
     * marks) are dropped from both sides, so neither a pasted stray one nor a
     * sender's padding changes what matches.
     */
    public static function fold(string $text): string
    {
        $text = preg_replace('/\p{Cf}+/u', '', mb_scrub($text, 'UTF-8')) ?? '';
        $composed = Normalizer::normalize($text, Normalizer::FORM_C);
        $folded = mb_convert_case(is_string($composed) ? $composed : $text, MB_CASE_FOLD, 'UTF-8');
        $recomposed = Normalizer::normalize($folded, Normalizer::FORM_C);

        return is_string($recomposed) ? $recomposed : $folded;
    }

    /**
     * Trims ordinary whitespace, the Unicode space separators (a pasted
     * no-break space, for one) and invisible format characters. Only run on
     * text of bounded length, so the two-sided pattern cannot be made to
     * backtrack on mail content.
     */
    public static function clean(string $text): string
    {
        $trimmed = preg_replace('/^[\s\p{Z}\p{Cc}\p{Cf}]+|[\s\p{Z}\p{Cc}\p{Cf}]+$/u', '', mb_scrub($text, 'UTF-8'));

        return $trimmed ?? '';
    }

    public static function matches(MailRule $rule, MessageFacts $facts): bool
    {
        $raw = (string) $rule->value;

        if (strlen($raw) > MailRule::MAX_PATTERN * 4) {
            return false;
        }

        $pattern = self::fold(self::clean($raw));

        if ($pattern === '') {
            return false;
        }

        return match ($rule->field) {
            MailRule::FROM => self::matchesSender($rule->operator, $pattern, $facts),
            MailRule::TO => self::matchesRecipients($rule->operator, $pattern, $facts->recipients),
            MailRule::SUBJECT => self::matchesSubject($rule->operator, $pattern, $facts->subject),
            default => false,
        };
    }

    /**
     * Every matching rule applies, in order. The first rule to name a folder
     * decides it, and a rule marked stop ends evaluation once it has matched.
     * One rule failing is logged and skipped; it must never cost the message
     * its other rules, let alone the sync.
     *
     * @param  iterable<MailRule>  $rules  already in the order they apply
     */
    public static function evaluate(iterable $rules, MessageFacts $facts): RuleOutcome
    {
        $archivedBy = null;
        $star = false;
        $read = false;
        $folder = null;
        $matched = [];

        foreach ($rules as $rule) {
            try {
                if (! self::matches($rule, $facts)) {
                    continue;
                }

                $action = $rule->action;
                $id = (int) $rule->id;
                $stop = (bool) $rule->stop_on_match;
                $target = $rule->folder;
            } catch (Throwable $e) {
                Log::warning('Mail rule skipped: '.$e->getMessage());

                continue;
            }

            $matched[] = $id;

            match ($action) {
                MailRule::ARCHIVE => $archivedBy ??= $id,
                MailRule::STAR => $star = true,
                MailRule::MARK_READ => $read = true,
                MailRule::FILE => $folder ??= $target,
                default => null,
            };

            if ($stop) {
                break;
            }
        }

        return new RuleOutcome(
            archivedByRuleId: $archivedBy,
            star: $star,
            markRead: $read,
            folder: $folder,
            matchedRuleIds: $matched,
        );
    }

    private static function matchesSender(string $operator, string $pattern, MessageFacts $facts): bool
    {
        $address = self::fold(trim((string) $facts->fromAddress));

        if ($operator === MailRule::CONTAINS) {
            return str_contains(self::fold((string) $facts->fromName)."\x1F".$address, $pattern);
        }

        return self::matchesAddress($operator, $pattern, $address);
    }

    /** @param list<string> $recipients */
    private static function matchesRecipients(string $operator, string $pattern, array $recipients): bool
    {
        foreach ($recipients as $recipient) {
            if ($operator === MailRule::CONTAINS) {
                if (str_contains(self::fold($recipient), $pattern)) {
                    return true;
                }

                continue;
            }

            if (self::matchesAddress($operator, $pattern, self::fold(self::addressIn($recipient)))) {
                return true;
            }
        }

        return false;
    }

    private static function matchesSubject(string $operator, string $pattern, string $subject): bool
    {
        return match ($operator) {
            MailRule::CONTAINS => str_contains(self::fold($subject), $pattern),
            MailRule::IS => strlen($subject) <= self::MAX_SUBJECT_FOR_EXACT && self::fold(self::clean($subject)) === $pattern,
            default => false,
        };
    }

    private static function matchesAddress(string $operator, string $pattern, string $address): bool
    {
        if ($address === '') {
            return false;
        }

        return match ($operator) {
            MailRule::IS => $address === $pattern,
            MailRule::DOMAIN => str_contains($address, '@') && substr($address, (int) strrpos($address, '@') + 1) === $pattern,
            default => false,
        };
    }

    /** "Name <a@b.test>" and a bare "a@b.test" both come out as the address. */
    private static function addressIn(string $recipient): string
    {
        $open = strrpos($recipient, '<');
        $close = strrpos($recipient, '>');

        if ($open !== false && $close !== false && $open < $close) {
            return trim(substr($recipient, $open + 1, $close - $open - 1));
        }

        return trim($recipient);
    }
}
