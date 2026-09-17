<?php
declare(strict_types=1);
namespace Lack\MailAutomation;

use Lack\MailAutomation\Attributes\OnFolderAutomation;
use PDO;
use Phore\Log\PhoreLogger;
use Phore\MailClient\Email;
use Phore\MailClient\MailClient;

final readonly class Rule
{
    public function __construct(
        public Folder|string $folder,
        public \Closure $matches,
        public \Closure $handle,
        public int $priority,
        public string $id,
        public bool $active,
        public ?string $flag,
        public int $order,
    ) {}
}

final class FolderRegistration
{
    public function __construct(private MailAutomation $automation, private Folder|string $folder) {}

    public function addAutomation(
        callable $matches,
        callable $handle,
        int $priority = 0,
        ?string $automationId = null,
        bool $active = true,
    ): MailAutomation {
        $this->automation->register($this->folder, $matches, $handle, $priority, $automationId, $active);
        return $this->automation;
    }
}

final class MailAutomation
{
    public const DEFAULT_AUTOMATION_FLAGS = [
        'processed' => 'lack_processed',
        'error' => 'lack_error',
        'actionRequired' => 'lack_action_required',
    ];

    private AutomationStorage $storage;
    private ContactResolver $resolver;
    private PhoreLogger $logger;
    /** @var array{processed:string,error:string,actionRequired:string} */
    private array $automationFlags;
    /** @var list<Rule> */
    private array $rules = [];
    /** @var array<string,true> */
    private array $ruleIds = [];
    /** @var array<string,true> Message-IDs handed off for the next run. */
    private array $deferred = [];
    private int $order = 0;

    public function __construct(
        private MailClient $client,
        PDO|AutomationStorage|string $storage,
        ?ContactResolver $contactResolver = null,
        private ?DraftSender $sender = null,
        ?PhoreLogger $logger = null,
    ) {
        $this->logger = ($logger ?? PhoreLogger::GetInstance())->scope('mailAutomation');
        $this->automationFlags = array_replace(self::DEFAULT_AUTOMATION_FLAGS, $client->automationFlags());
        if (is_string($storage)) {
            if ($storage === '') { throw new \InvalidArgumentException('SQLite storage path must not be empty.'); }
            $storage = new PDO('sqlite:' . $storage);
        }
        $this->storage = $storage instanceof PDO ? new SqliteStorage($storage) : $storage;
        $this->storage->bindAccount($client->accountId());
        if ($client->fromAddress() === null) { throw new \InvalidArgumentException('Mail automation requires a configured From address.'); }
        $this->resolver = $contactResolver ?? new ReplyContactResolver();
        $this->resolver->bind($client, $this->storage);
        $this->logger->debug('Initialized mail automation for account {}', [$client->accountId()]);
    }

    public function storage(): AutomationStorage { return $this->storage; }
    public function logger(): PhoreLogger { return $this->logger; }
    public function onFolder(Folder|string $folder): FolderRegistration { return new FolderRegistration($this, $folder); }

    public function register(
        Folder|string $folder,
        callable $matches,
        callable $handle,
        int $priority = 0,
        ?string $automationId = null,
        bool $active = true,
        ?string $flag = null,
    ): void {
        $id = $automationId ?? $this->inferId($handle);
        if (isset($this->ruleIds[$id])) { throw new \InvalidArgumentException('Duplicate automationId: ' . $id); }
        $this->ruleIds[$id] = true;
        $this->rules[] = new Rule(
            $folder,
            \Closure::fromCallable($matches),
            \Closure::fromCallable($handle),
            $priority,
            $id,
            $active,
            $flag,
            $this->order++,
        );
        $this->logger->debug('Registered automation {} for folder {}', [$id, $folder instanceof Folder ? $folder->value : $folder]);
    }

    public function addRules(object|callable|string $rules): self
    {
        if (is_string($rules) && function_exists($rules)) {
            $this->registerAttributedCallable(new \ReflectionFunction($rules), $rules);
            return $this;
        }
        if (is_object($rules)) {
            $reflection = new \ReflectionObject($rules);
            if (is_callable($rules) && $reflection->hasMethod('__invoke')) {
                $method = $reflection->getMethod('__invoke');
                $attributes = [...$reflection->getAttributes(OnFolderAutomation::class), ...$method->getAttributes(OnFolderAutomation::class)];
                $this->registerFromAttributes($attributes, $rules, $reflection->getShortName());
            }
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getName() === '__invoke') { continue; }
                $attributes = $method->getAttributes(OnFolderAutomation::class);
                if ($attributes !== []) {
                    $this->registerFromAttributes($attributes, [$rules,$method->getName()], $reflection->getShortName() . '::' . $method->getName());
                }
            }
            return $this;
        }
        if (is_callable($rules)) {
            $reflection = new \ReflectionFunction(\Closure::fromCallable($rules));
            $this->registerAttributedCallable($reflection, $rules);
            return $this;
        }
        throw new \InvalidArgumentException('Unsupported rule registration.');
    }

    private function registerAttributedCallable(\ReflectionFunction $reflection, callable $callable): void
    {
        $this->registerFromAttributes($reflection->getAttributes(OnFolderAutomation::class), $callable, $reflection->getName());
    }

    private function registerFromAttributes(array $attributes, callable $callable, string $defaultId): void
    {
        if ($attributes === []) { throw new \InvalidArgumentException('No OnFolderAutomation attribute found.'); }
        foreach ($attributes as $index => $attribute) {
            /** @var OnFolderAutomation $config */
            $config = $attribute->newInstance();
            $matches = $config->flag === null
                ? static fn(Email $mail, MailContext $context): bool => true
                : static fn(Email $mail, MailContext $context): bool => $context->hasFlag($config->flag);
            $this->register(
                $config->folder,
                $matches,
                $callable,
                $config->priority,
                $config->automationId ?? ($index === 0 ? $defaultId : $defaultId . '#' . ($index + 1)),
                $config->active,
                $config->flag,
            );
        }
    }

    private function inferId(callable $callable): string
    {
        if (is_array($callable)) {
            $class = is_object($callable[0]) ? $callable[0]::class : (string)$callable[0];
            return basename(str_replace('\\','/',$class)) . '::' . $callable[1];
        }
        if (is_string($callable)) { return $callable; }
        if (is_object($callable) && !$callable instanceof \Closure) { return basename(str_replace('\\','/',$callable::class)); }
        return 'closure-' . ($this->order + 1);
    }

    public function run(bool $processExistingOutgoing = false, bool $dryRun = false): RunReport
    {
        $report = new RunReport();
        $this->deferred = [];
        $this->logger->debug('Start run with {} registered automations', [count($this->rules)]);
        $sent = Folder::Sent->resolve($this->client);
        $folders = [$sent];
        foreach ($this->rules as $rule) {
            $folder = $this->resolveFolder($rule->folder);
            if (!in_array($folder, $folders, true)) { $folders[] = $folder; }
        }
        foreach ($folders as $folder) {
            $this->drainFolder($folder, $folder === $sent, $processExistingOutgoing, $dryRun, $report);
        }
        $this->logger->debug('Run finished: {} processed, {} skipped, {} indexed sent', [$report->processed, $report->skipped, $report->indexedSent]);
        return $report;
    }

    private function drainFolder(string $folder, bool $isSent, bool $processExistingOutgoing, bool $dryRun, RunReport $report): void
    {
        $log = $this->logger->scope('sync')->withContext(['folder' => $folder]);
        $cursor = $this->storage->cursor($folder);
        $baselineSent = $isSent && $cursor === null && !$processExistingOutgoing;
        $log->debug('Start folder sync with cursor {}', [$cursor]);
        do {
            try {
                $changes = $this->client->syncFolder($folder, $cursor, 100);
                $log->debug('Fetched {} added messages and {} flag changes', [count($changes->added), count($changes->flagsChanged)]);
            } catch (\Throwable $error) {
                $log->error('Folder sync failed: {}', [$error->getMessage(), 'exception' => $error]);
                $report->addError($folder, null, $error);
                return;
            }

            $messages = $changes->added;
            foreach ($changes->flagsChanged as $change) {
                try { $messages[] = $this->client->peek($change->id); }
                catch (\Throwable $error) {
                    $log->error('Loading changed message failed: {}', [$error->getMessage(), 'exception' => $error]);
                    $report->addError($folder, null, $error);
                    return;
                }
            }

            foreach ($messages as $mail) {
                if ($mail->messageId() !== null && isset($this->deferred[$mail->messageId()])) {
                    $log->debug('Stop folder sync for deferred message {}', [$mail->messageId()]);
                    return;
                }
                if ($isSent) { $this->indexSent($mail, $folder, $report); }
                if ($baselineSent) {
                    if ($this->blockingFlag($mail) !== null) {
                        $report->skipped++;
                        continue;
                    }
                    if (!$dryRun) {
                        try {
                            $this->client->addFlag($mail, $this->automationFlags['processed']);
                            $log->debug('Baseline sent message marked processed without automation');
                        } catch (\Throwable $error) {
                            $log->error('Marking baseline sent message failed: {}', [$error->getMessage(), 'exception' => $error]);
                            $report->addError($folder,$mail->messageId(),$error);
                            return;
                        }
                    }
                    $report->skipped++;
                    continue;
                }
                $direction = $isSent ? 'outgoing' : 'incoming';
                try {
                    $this->processMessage($mail, $folder, $direction, $dryRun, $report);
                } catch (\Throwable $error) {
                    $senders = array_map(static fn($address): string => $address->getAddress(), $mail->from());
                    $messageError = new \RuntimeException(sprintf(
                        'Message processing failed: %s Processing context: operation="process %s message", folder="%s", message-id="%s", from="%s", date="%s", subject="%s".',
                        $error->getMessage(),
                        $direction,
                        $folder,
                        $mail->messageId() ?? $mail->id() ?? 'unknown',
                        $senders === [] ? 'unknown' : implode(', ', $senders),
                        $mail->date()?->format(DATE_ATOM) ?? 'unknown',
                        $mail->subject(),
                    ), previous: $error);
                    $log->error('{:full}', [$messageError->getMessage(), 'exception' => $messageError]);
                    if (!$dryRun) {
                        try {
                            $this->client->addFlag($mail, $this->automationFlags['error']);
                            $log->debug('Marked failed message with error flag {}', [$this->automationFlags['error']]);
                        } catch (\Throwable $flagError) {
                            $log->error('Marking failed message with error flag failed: {}', [$flagError->getMessage(), 'exception' => $flagError]);
                        }
                    }
                    $report->addError($folder, $mail->messageId(), $messageError);
                    return;
                }
            }

            if (!$dryRun) {
                $this->storage->saveCursor($folder, $changes->nextCursor);
                $log->debug('Saved folder cursor {}', [$changes->nextCursor]);
            }
            $cursor = $changes->nextCursor;
        } while ($changes->hasMore);
    }

    private function indexSent(Email $mail, string $folder, RunReport $report): void
    {
        $own = $this->client->fromAddress()?->getAddress();
        $recipients = [];
        foreach ([...$mail->to(), ...$mail->cc(), ...$mail->bcc()] as $address) {
            if ($address->getAddress() !== $own) { $recipients[$address->getAddress()] = true; }
        }
        if (count($recipients) === 1 && count($mail->from()) === 1 && $mail->from()[0]->getAddress() === $own) {
            $this->storage->recordSent($mail, $folder, array_key_first($recipients));
            $report->indexedSent++;
            $this->logger->scope('sent')->debug('Indexed sent message {}', [$mail->messageId() ?? $mail->id() ?? 'unknown']);
        }
    }

    private function processMessage(Email $mail, string $folder, string $direction, bool $dryRun, RunReport $report): void
    {
        $messageId = $mail->messageId() ?? $mail->id() ?? 'unknown';
        $messageLog = $this->logger->scope('message')->withContext(['messageId' => $messageId, 'folder' => $folder, 'direction' => $direction]);
        $blockingFlag = $this->blockingFlag($mail);
        if ($blockingFlag !== null) {
            $report->skipped++;
            $messageLog->debug('Skip message because blocking automation flag {} is set', [$blockingFlag]);
            return;
        }

        $messageLog->debug('Process message');
        if ($direction === 'incoming') {
            $resolution = $this->resolver->resolve($mail);
        } else {
            $contacts = [];
            foreach ([...$mail->to(), ...$mail->cc(), ...$mail->bcc()] as $address) {
                if ($this->client->fromAddress() !== null && $address->getAddress() === $this->client->fromAddress()->getAddress()) { continue; }
                $contacts[] = $this->storage->contacts()->findByEmail($address->getAddress());
            }
            $contact = count($contacts) === 1 ? $contacts[0] : null;
            $resolution = new ContactResolution($contact === null ? ContactResolutionStatus::Unknown : ContactResolutionStatus::KnownAddress, $contact);
        }
        $messageLog->debug('Contact resolution status {}', [$resolution->status->value]);

        $context = new MailContext($mail,$folder,$direction,$resolution->contact,$resolution,$messageLog,$dryRun,$this->storage,$this->client);
        $rules = $this->rulesFor($folder);
        $final = $mail;
        $handled = false;
        $reprocess = false;
        $actionRequired = false;

        foreach ($rules as $rule) {
            $context->logger = $messageLog->scope('automation')->withContext(['automationId' => $rule->id]);
            if (!$rule->active) {
                $context->logger->debug('Skip inactive automation {}', [$rule->id]);
                continue;
            }
            if ($rule->flag !== null && !$context->hasFlag($rule->flag)) {
                $context->logger->debug('Skip automation {} because flag {} is missing', [$rule->id, $rule->flag]);
                continue;
            }
            $context->logger->debug('Evaluate automation {}', [$rule->id]);
            if (!(($rule->matches)($mail,$context))) {
                $context->logger->debug('Automation {} did not match', [$rule->id]);
                continue;
            }
            $actions = ($rule->handle)($mail,$context);
            if (!$actions instanceof MailAction) { throw new \UnexpectedValueException('Automation handlers must return MailAction.'); }
            if ($actions->isPass()) {
                $context->logger->debug('Automation {} returned pass', [$rule->id]);
                continue;
            }
            $handled = true;
            $context->logger->debug('Automation {} matched', [$rule->id]);
            if ($actions->isActionRequired()) {
                $actionRequired = true;
            } elseif (!$actions->isComplete()) {
                [$final,$reprocess] = $this->executeActions($final,$actions,$context->logger->scope('actions'));
            }
            break;
        }

        if (!$handled) {
            $report->skipped++;
            $messageLog->debug('Finished message processing without matching automation');
            return;
        }
        if (!$dryRun && $actionRequired) {
            $final = $this->client->addFlag($final, $this->automationFlags['actionRequired']);
        } elseif (!$dryRun && !$reprocess) {
            $final = $this->client->addFlag($final, $this->automationFlags['processed']);
        }
        if ($reprocess && $final->messageId() !== null) { $this->deferred[$final->messageId()] = true; }
        $this->storage->recordHistory($resolution->contact?->id,$final,$direction,$folder);
        $report->processed++;
        $messageLog->debug('Finished message processing: handled={}, reprocess={}, actionRequired={}', [$handled, $reprocess, $actionRequired]);
    }

    private function blockingFlag(Email $mail): ?string
    {
        foreach ($this->automationFlags as $flag) {
            if (in_array($flag, $mail->flags(), true)) { return $flag; }
        }
        return null;
    }

    private function executeActions(Email $mail, MailAction $actions, PhoreLogger $logger): array
    {
        $current = $mail;
        $reprocess = false;
        foreach ($actions->items() as $item) {
            $logger->debug('Execute action {}', [$item['type']]);
            switch ($item['type']) {
                case 'addFlag':
                    $current = $this->client->addFlag($current,$item['args'][0]);
                    break;
                case 'removeFlag':
                    $current = $this->client->removeFlag($current,$item['args'][0]);
                    break;
                case 'sendReply':
                    $reply = $this->client->reply($current,$item['args'][0]);
                    if ($this->sender === null) {
                        $this->client->saveDraft($reply);
                    } else {
                        $this->sender->send($reply);
                    }
                    break;
                case 'moveTo':
                    [$alias,$handoff] = $item['args'];
                    $target = $this->client->managedFolder($alias);
                    $current = $this->client->moveTo($current,$target);
                    $reprocess = (bool)$handoff;
                    if ($reprocess && $this->rulesFor($target) === []) { throw new \RuntimeException('Reprocess target has no registered automation chain.'); }
                    break;
                case 'moveToRaw':
                    [$target,$handoff] = $item['args'];
                    $current = $this->client->moveTo($current,$target);
                    $reprocess = (bool)$handoff;
                    if ($reprocess && $this->rulesFor($target) === []) { throw new \RuntimeException('Reprocess target has no registered automation chain.'); }
                    break;
                default:
                    throw new \LogicException('Unknown mail action: ' . $item['type']);
            }
        }
        return [$current,$reprocess];
    }

    /** @return list<Rule> */
    private function rulesFor(string $folder): array
    {
        $rules = array_values(array_filter($this->rules, fn(Rule $rule): bool => $this->resolveFolder($rule->folder) === $folder));
        usort($rules, static fn(Rule $a, Rule $b): int => $b->priority <=> $a->priority ?: $a->order <=> $b->order);
        return $rules;
    }

    private function resolveFolder(Folder|string $folder): string
    { return $folder instanceof Folder ? $folder->resolve($this->client) : $folder; }
}
