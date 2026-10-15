<?php

namespace App\Support\MailRules;

/**
 * Everything the matching rules decided for one message. Archive is a local
 * flag, so only the rule that did it is recorded (for the undo list); the
 * other three have to reach the mail server.
 */
final readonly class RuleOutcome
{
    /** @param list<int> $matchedRuleIds */
    public function __construct(
        public ?int $archivedByRuleId = null,
        public bool $star = false,
        public bool $markRead = false,
        public ?string $folder = null,
        public array $matchedRuleIds = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->matchedRuleIds === [];
    }

    /** True when the message leaves the inbox view on arrival, so a new-mail toast would be wrong. */
    public function keepsOutOfSight(): bool
    {
        return $this->archivedByRuleId !== null || $this->folder !== null;
    }
}
