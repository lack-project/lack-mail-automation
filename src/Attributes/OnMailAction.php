<?php

declare(strict_types=1);

namespace Lack\MailAutomation\Attributes;

use Attribute;
use InvalidArgumentException;
use Lack\MailAutomation\Folder;

/**
 * Declares one semantic mail action.
 *
 * The optional deterministic `when` guard always runs before the AI condition.
 * PHP attributes cannot contain closures; use a same-object method name or a
 * static callable array such as `[MyAction::class, 'accepts']`.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class OnMailAction
{
    /**
     * @param ?string $condition Natural-language condition evaluated against the
     *   complete analyzed conversation. Null means a successful `when` guard is
     *   sufficient and no AI choice is required for this action.
     * @param array|string|null $when Same-object public method name or static callable array.
     * @param Folder|string $folder Source folder for this action.
     * @param ?string $id Stable choice ID; defaults to class or method name.
     * @throws InvalidArgumentException For unusable declarations.
     * @example #[OnMailAction(condition: 'The applicant asks to update the profile.')]
     * @example #[OnMailAction(when: [MetaLead::class, 'isSource'], condition: 'The message only contains applicant lead data.')]
     * @see \Lack\MailAutomation\MailAutomation::addMailActions()
     */
    public function __construct(
        public ?string $condition = null,
        public array|string|null $when = null,
        public Folder|string $folder = Folder::Inbox,
        public ?string $id = null,
    ) {
        if ($condition !== null && trim($condition) === '') {
            throw new InvalidArgumentException('Mail action condition must be null or nonempty.');
        }
        if ($condition === null && $when === null) {
            throw new InvalidArgumentException('Mail action requires a condition, a deterministic when guard, or both.');
        }
        if ($id !== null && (trim($id) === '' || is_numeric($id))) {
            throw new InvalidArgumentException('Mail action ID must be nonempty and nonnumeric.');
        }
        if (is_string($when) && trim($when) === '') {
            throw new InvalidArgumentException('Mail action when method must not be empty.');
        }
        if (is_array($when) && (count($when) !== 2 || !isset($when[0], $when[1]) || !is_string($when[0]) || !is_string($when[1]))) {
            throw new InvalidArgumentException('Mail action static when callback must be [class, method].');
        }
        if (is_string($folder) && (trim($folder) === '' || preg_match('/[\r\n\x00]/', $folder))) {
            throw new InvalidArgumentException('Mail action folder must be nonempty and contain no control characters.');
        }
    }
}
