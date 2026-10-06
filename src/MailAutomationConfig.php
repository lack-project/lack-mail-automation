<?php

declare(strict_types=1);

namespace Lack\MailAutomation;

use Phore\Log\PhoreLogger;
use Phore\MailClient\MailboxConfig;

/**
 * Application configuration for the standard MailAutomation bootstrap.
 *
 * Mailbox folders and automation flags remain part of MailboxConfig. This
 * object adds only automation-owned paths and AI/action-discovery settings.
 */
final readonly class MailAutomationConfig
{
    /**
     * @param MailboxConfig|string $mailbox Mailbox config object or local YAML/JSON file.
     * @param string $storage SQLite state path.
     * @param string $actionsDirectory Directory scanned recursively for mail actions.
     * @param array<string,mixed> $aiOptions Options passed to the AI harness.
     * @param string $actionPattern Filename pattern for discovered action classes.
     * @param bool $flagUnhandled Flag unmatched AI-routed messages for manual review.
     * @param string|null $lockFile Lock path; defaults to "<storage>.lock".
     * @param PhoreLogger|null $logger Optional application logger.
     *
     * @example
     * $config = new MailAutomationConfig(
     *     mailbox: __DIR__ . '/mailbox.yaml',
     *     storage: __DIR__ . '/run/mail.sqlite',
     *     actionsDirectory: __DIR__ . '/actions',
     * );
     * @see MailAutomation::fromConfig()
     */
    public function __construct(
        public MailboxConfig|string $mailbox,
        public string $storage,
        public string $actionsDirectory,
        public array $aiOptions = [],
        public string $actionPattern = '*Action.php',
        public bool $flagUnhandled = true,
        public ?string $lockFile = null,
        public ?PhoreLogger $logger = null,
    ) {
        if (trim($storage) === '') {
            throw new \InvalidArgumentException('Mail automation storage path must not be empty.');
        }
        if (trim($actionsDirectory) === '') {
            throw new \InvalidArgumentException('Mail automation actions directory must not be empty.');
        }
        if (trim($actionPattern) === '') {
            throw new \InvalidArgumentException('Mail automation action pattern must not be empty.');
        }
    }
}
