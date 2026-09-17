<?php
declare(strict_types=1);
namespace Lack\MailAutomation;

interface MailAction
{
    public function isPass(): bool;
    public function isComplete(): bool;
    public function isActionRequired(): bool;
    /** @return list<array{type:string,args:array}> */
    public function items(): array;
}

/** Factory for mail-action results returned by automation handlers. */
final class MailActions
{
    private function __construct() {}

    /**
     * Start a mutable fluent action schedule.
     * Scheduled actions are executed automatically by MailAutomation after the handler returns.
     */
    public static function schedule(): ScheduledMailActions
    { return ScheduledMailActions::schedule(); }

    /** Convenience factory for a managed-folder move. */
    public static function moveTo(string $folderAlias, bool $reprocess = false): ScheduledMailActions
    { return self::schedule()->moveTo($folderAlias,$reprocess); }

    /** Convenience factory for an exact existing IMAP-folder move. */
    public static function moveToRaw(string $folder, bool $reprocess = false): ScheduledMailActions
    { return self::schedule()->moveToRaw($folder,$reprocess); }

    /** Delegate this message to the next matching automation rule without executing actions. */
    public static function pass(): ScheduledMailActions
    { return ScheduledMailActions::pass(); }

    /** Finish this message as handled without executing mail actions. */
    public static function complete(): ScheduledMailActions
    { return ScheduledMailActions::complete(); }

    /** Stop automatic processing until a human clears the configured action-required IMAP keyword. */
    public static function actionRequired(): ScheduledMailActions
    { return ScheduledMailActions::actionRequired(); }
}

/**
 * Mutable fluent result used to schedule mail modifications.
 * MailAutomation executes queued actions only after the handler has returned this object.
 */
final class ScheduledMailActions implements MailAction
{
    private const MODE_ACTIONS = 'actions';
    private const MODE_PASS = 'pass';
    private const MODE_COMPLETE = 'complete';
    private const MODE_ACTION_REQUIRED = 'action_required';

    /** @var list<array{type:string,args:array}> */
    private array $actions = [];

    private function __construct(private string $mode) {}

    public static function schedule(): self { return new self(self::MODE_ACTIONS); }
    public static function pass(): self { return new self(self::MODE_PASS); }
    public static function complete(): self { return new self(self::MODE_COMPLETE); }
    public static function actionRequired(): self { return new self(self::MODE_ACTION_REQUIRED); }

    public function isPass(): bool { return $this->mode === self::MODE_PASS; }
    public function isComplete(): bool { return $this->mode === self::MODE_COMPLETE; }
    public function isActionRequired(): bool { return $this->mode === self::MODE_ACTION_REQUIRED; }
    public function items(): array { return $this->actions; }

    private function queue(string $type, array $args): self
    {
        if ($this->mode !== self::MODE_ACTIONS) { throw new \LogicException('pass(), complete() and actionRequired() cannot contain scheduled actions.'); }
        $this->actions[] = ['type'=>$type,'args'=>$args];
        return $this;
    }

    public function addFlag(string $flag): self
    {
        self::assertNotDefaultAutomationFlag($flag);
        return $this->queue('addFlag', [$flag]);
    }

    public function removeFlag(string $flag): self
    {
        self::assertNotDefaultAutomationFlag($flag);
        return $this->queue('removeFlag', [$flag]);
    }

    private static function assertNotDefaultAutomationFlag(string $flag): void
    {
        if (in_array($flag, array_values(MailAutomation::DEFAULT_AUTOMATION_FLAGS), true)) {
            throw new \InvalidArgumentException($flag . ' is reserved by the automation engine.');
        }
    }

    /**
     * Schedule a move to a managed folder alias declared in MailClient mailbox config `managedFolders`.
     * Example config: `managedFolders: { customers: Customers }`, then call `moveTo('customers')`.
     */
    public function moveTo(string $folderAlias, bool $reprocess = false): self
    { return $this->queue('moveTo', [$folderAlias,$reprocess]); }

    /** Schedule a move to an exact existing IMAP folder name without alias resolution. */
    public function moveToRaw(string $folder, bool $reprocess = false): self
    { return $this->queue('moveToRaw', [$folder,$reprocess]); }

    public function sendReply(string $markdown): self
    { return $this->queue('sendReply', [$markdown]); }
}

final class RunError
{
    public function __construct(
        public readonly string $folder,
        public readonly ?string $messageId,
        public readonly \Throwable $error,
    ) {}
}

final class RunReport
{
    public int $processed = 0;
    public int $skipped = 0;
    public int $indexedSent = 0;
    /** @var list<RunError> */
    private array $errors = [];

    public function addError(string $folder, ?string $messageId, \Throwable $error): void
    { $this->errors[] = new RunError($folder,$messageId,$error); }

    public function errors(): array { return $this->errors; }
    public function successful(): bool { return $this->errors === []; }
}
