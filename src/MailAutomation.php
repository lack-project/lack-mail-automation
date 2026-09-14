<?php
declare(strict_types=1);
namespace Lack\MailAutomation;

use Lack\MailAutomation\Attributes\OnFolderAutomation;
use PDO;
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
    public const PROCESSED_FLAG = 'phore_processed';

    private AutomationStorage $storage;
    private ContactResolver $resolver;
    /** @var list<Rule> */
    private array $rules = [];
    /** @var array<string,true> */
    private array $ruleIds = [];
    /** @var array<string,true> Message-IDs handed off for the next run. */
    private array $deferred = [];
    private int $order = 0;

    public function __construct(
        private MailClient $client,
        PDO|AutomationStorage $storage,
        ?ContactResolver $contactResolver = null,
        private ?DraftSender $sender = null,
    ) {
        $this->storage = $storage instanceof PDO ? new SqliteStorage($storage) : $storage;
        $this->storage->bindAccount($client->accountId());
        if ($client->fromAddress() === null) { throw new \InvalidArgumentException('Mail automation requires a configured From address.'); }
        $this->resolver = $contactResolver ?? new ReplyContactResolver();
        $this->resolver->bind($client, $this->storage);
    }

    public function storage(): AutomationStorage { return $this->storage; }
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

    public function run(bool $processExistingOutgoing = false): RunReport
    {
        $report = new RunReport();
        $this->deferred = [];
        $sent = Folder::Sent->resolve($this->client);
        $folders = [$sent];
        foreach ($this->rules as $rule) {
            $folder = $this->resolveFolder($rule->folder);
            if (!in_array($folder, $folders, true)) { $folders[] = $folder; }
        }
        foreach ($folders as $folder) {
            $this->drainFolder($folder, $folder === $sent, $processExistingOutgoing, $report);
        }
        return $report;
    }

    private function drainFolder(string $folder, bool $isSent, bool $processExistingOutgoing, RunReport $report): void
    {
        $cursor = $this->storage->cursor($folder);
        $baselineSent = $isSent && $cursor === null && !$processExistingOutgoing;
        do {
            try {
                $changes = $this->client->syncFolder($folder, $cursor, 100);
            } catch (\Throwable $error) {
                $report->addError($folder, null, $error);
                return;
            }

            $messages = $changes->added;
            foreach ($changes->flagsChanged as $change) {
                try { $messages[] = $this->client->peek($change->id); }
                catch (\Throwable $error) { $report->addError($folder, null, $error); return; }
            }

            foreach ($messages as $mail) {
                if ($mail->messageId() !== null && isset($this->deferred[$mail->messageId()])) {
                    // Do not advance this folder cursor; the destination must be observed next run.
                    return;
                }
                if ($isSent) { $this->indexSent($mail, $folder, $report); }
                if ($baselineSent && !in_array(self::PROCESSED_FLAG, $mail->flags(), true)) {
                    try { $this->client->addFlag($mail, self::PROCESSED_FLAG); $report->skipped++; }
                    catch (\Throwable $error) { $report->addError($folder,$mail->messageId(),$error); return; }
                    continue;
                }
                try {
                    $this->processMessage($mail, $folder, $isSent ? 'outgoing' : 'incoming', $report);
                } catch (\Throwable $error) {
                    $report->addError($folder, $mail->messageId(), $error);
                    return;
                }
            }

            $this->storage->saveCursor($folder, $changes->nextCursor);
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
        }
    }

    private function processMessage(Email $mail, string $folder, string $direction, RunReport $report): void
    {
        if (in_array(self::PROCESSED_FLAG, $mail->flags(), true)) { $report->skipped++; return; }

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

        $context = new MailContext($mail,$folder,$direction,$resolution->contact,$resolution,$this->storage,$this->client);
        $rules = $this->rulesFor($folder);
        $final = $mail;
        $handled = false;
        $reprocess = false;

        foreach ($rules as $rule) {
            if (!$rule->active) { continue; }
            if ($rule->flag !== null && !$context->hasFlag($rule->flag)) { continue; }
            if (!(($rule->matches)($mail,$context))) { continue; }
            $actions = ($rule->handle)($mail,$context);
            if (!$actions instanceof MailActions) { throw new \UnexpectedValueException('Automation handlers must return MailActions.'); }
            if ($actions->isPass()) { continue; }
            $handled = true;
            if (!$actions->isComplete()) {
                [$final,$reprocess] = $this->executeActions($final,$actions);
            }
            break;
        }

        if (!$reprocess) { $final = $this->client->addFlag($final, self::PROCESSED_FLAG); }
        if ($reprocess && $final->messageId() !== null) { $this->deferred[$final->messageId()] = true; }
        $this->storage->recordHistory($resolution->contact?->id,$final,$direction,$folder);
        $report->processed++;
    }

    private function executeActions(Email $mail, MailActions $actions): array
    {
        $current = $mail;
        $reprocess = false;
        foreach ($actions->items() as $item) {
            switch ($item['type']) {
                case 'addFlag':
                    $current = $this->client->addFlag($current,$item['args'][0]);
                    break;
                case 'removeFlag':
                    $current = $this->client->removeFlag($current,$item['args'][0]);
                    break;
                case 'sendReply':
                    if ($this->sender === null) { throw new \RuntimeException('sendReply requires a DraftSender.'); }
                    $this->sender->send($this->client->reply($current,$item['args'][0]));
                    break;
                case 'moveTo':
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
