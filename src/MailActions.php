<?php
declare(strict_types=1);
namespace Lack\MailAutomation;

final class MailActions
{
    private const MODE_ACTIONS = 'actions';
    private const MODE_PASS = 'pass';
    private const MODE_COMPLETE = 'complete';

    /** @var list<array{type:string,args:array}> */
    private array $actions = [];

    private function __construct(private string $mode) {}

    public static function create(): self { return new self(self::MODE_ACTIONS); }
    public static function pass(): self { return new self(self::MODE_PASS); }
    public static function complete(): self { return new self(self::MODE_COMPLETE); }

    public function isPass(): bool { return $this->mode === self::MODE_PASS; }
    public function isComplete(): bool { return $this->mode === self::MODE_COMPLETE; }
    public function items(): array { return $this->actions; }

    private function queue(string $type, array $args): self
    {
        if ($this->mode !== self::MODE_ACTIONS) { throw new \LogicException('pass() and complete() cannot contain actions.'); }
        $this->actions[] = ['type'=>$type,'args'=>$args];
        return $this;
    }

    public function addFlag(string $flag): self
    {
        if ($flag === 'phore_processed') { throw new \InvalidArgumentException('phore_processed is reserved by the engine.'); }
        return $this->queue('addFlag', [$flag]);
    }

    public function removeFlag(string $flag): self
    {
        if ($flag === 'phore_processed') { throw new \InvalidArgumentException('phore_processed is reserved by the engine.'); }
        return $this->queue('removeFlag', [$flag]);
    }

    /**
     * Move to a managed folder alias declared in MailClient mailbox config `managedFolders`.
     * Example config: `managedFolders: { customers: Customers }`, then call `moveTo('customers')`.
     */
    public function moveTo(string $folderAlias, bool $reprocess = false): self
    { return $this->queue('moveTo', [$folderAlias,$reprocess]); }

    /** Move to an exact existing IMAP folder name without managed-folder alias resolution. */
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
