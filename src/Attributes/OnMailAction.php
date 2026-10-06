<?php

declare(strict_types=1);

namespace Lack\MailAutomation\Attributes;

use Attribute;
use InvalidArgumentException;
use Lack\MailAutomation\Folder;

/**
 * Declares one AI-routed business action for an analyzed mail.
 *
 * The condition is the complete business-routing rule and is evaluated against
 * the prepared mail conversation by the AI matcher.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class OnMailAction
{
    /**
     * @param string $condition Natural-language condition for selecting this action.
     * @param Folder|string $folder Source folder for this action.
     * @param ?string $id Stable choice ID; defaults to class or method name.
     * @throws InvalidArgumentException For unusable declarations.
     * @example #[OnMailAction(condition: 'This is the first reply to our initial applicant mail.')]
     * @see \Lack\MailAutomation\MailAutomation::addMailActions()
     */
    public function __construct(
        public string $condition,
        public Folder|string $folder = Folder::Inbox,
        public ?string $id = null,
    ) {
        if (trim($condition) === '') {
            throw new InvalidArgumentException('Mail action condition must not be empty.');
        }
        if ($id !== null && (trim($id) === '' || is_numeric($id))) {
            throw new InvalidArgumentException('Mail action ID must be nonempty and nonnumeric.');
        }
        if (is_string($folder) && (trim($folder) === '' || preg_match('/[\r\n\x00]/', $folder))) {
            throw new InvalidArgumentException('Mail action folder must be nonempty and contain no control characters.');
        }
    }
}
