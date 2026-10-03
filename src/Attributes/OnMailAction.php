<?php

declare(strict_types=1);

namespace Lack\MailAutomation\Attributes;

use Attribute;
use InvalidArgumentException;
use Lack\MailAutomation\Folder;

/** A semantic action and its source folder, never an IMAP flag predicate. */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class OnMailAction
{
    /**
     * @param string $condition Trusted application rule presented to the AI.
     * @param ?string $id Stable choice ID; defaults to the annotated method name.
     * @param ?string $when Public, side-effect-free eligibility method on the same
     *   object: (AnalyzedMail, MailContext): bool. Enforce permissions here, not in AI.
     * @param Folder|string $folder Standard folder or exact IMAP folder name in
     *   this automation's account. Strings are not managed-folder aliases.
     * @throws InvalidArgumentException For empty condition, ID, guard or folder.
     * @example #[OnMailAction('An existing user requests a profile update.', when: 'hasProfile', folder: Folder::Inbox)]
     * @see \Lack\MailAutomation\MailAutomation::addMailActions()
     */
    public function __construct(
        public string $condition,
        public ?string $id = null,
        public ?string $when = null,
        public Folder|string $folder = Folder::Inbox,
    ) {
        if (trim($condition) === '' || ($id !== null && (trim($id) === '' || is_numeric($id))) || ($when !== null && trim($when) === '')) {
            throw new InvalidArgumentException('An action requires a condition and nonempty, nonnumeric ID/guard names.');
        }
        if (is_string($folder) && (trim($folder) === '' || preg_match('/[\r\n\x00]/', $folder))) {
            throw new InvalidArgumentException('An action requires a nonempty source folder without control characters.');
        }
    }
}
