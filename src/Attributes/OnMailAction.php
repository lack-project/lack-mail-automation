<?php

declare(strict_types=1);

namespace Lack\MailAutomation\Attributes;

use Attribute;
use InvalidArgumentException;

/** A semantic choice on an application method, not an IMAP flag predicate. */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class OnMailAction
{
    /**
     * Describe when this action fits the current mail and its conversation.
     *
     * @param string $condition Trusted application rule presented to the AI.
     * @param ?string $id Stable choice ID; defaults to the annotated method name.
     * @param ?string $when Public method on the same object, accepting
     *   (AnalyzedMail, MailContext): bool. It runs before AI selection and must
     *   perform deterministic eligibility checks without side effects.
     * @throws InvalidArgumentException For empty condition, ID or guard name.
     * @example #[OnMailAction('The user explicitly requests a profile change.', when: 'hasProfile')]
     * @see \Lack\MailAutomation\Analysis\MailActionMatcher
     */
    public function __construct(public string $condition, public ?string $id = null, public ?string $when = null)
    {
        if (trim($condition) === '' || ($id !== null && (trim($id) === '' || is_numeric($id))) || ($when !== null && trim($when) === '')) {
            throw new InvalidArgumentException('An action requires a condition and nonempty, nonnumeric ID/guard names.');
        }
    }
}
