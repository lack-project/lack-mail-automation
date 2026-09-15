<?php
declare(strict_types=1);
namespace Lack\MailAutomation;

use Phore\Log\PhoreLogger;
use Phore\MailClient\Email;
use Phore\MailClient\MailClient;

interface ContactResolver
{
    public function bind(MailClient $client, AutomationStorage $storage): void;
    public function resolve(Email $mail): ContactResolution;
}

interface DraftSender
{
    public function send(Email $draft): void;
}

enum ContactResolutionStatus: string
{
    case Unknown = 'unknown';
    case KnownAddress = 'known_address';
    case ContactCreated = 'contact_created';
    case AliasAdded = 'alias_added';
    case Conflict = 'conflict';
    case OutgoingMissing = 'outgoing_missing';
}

final readonly class MatchedOutgoing
{
    public function __construct(
        public string $messageId,
        public string $recipientEmail,
        public string $folder,
        public string $serverId,
    ) {}
}

final readonly class ContactResolution
{
    public function __construct(
        public ContactResolutionStatus $status,
        public ?Contact $contact = null,
        public bool $aliasAdded = false,
        public ?MatchedOutgoing $matchedOutgoing = null,
    ) {}

    public function needsReview(): bool
    { return in_array($this->status, [ContactResolutionStatus::Conflict, ContactResolutionStatus::OutgoingMissing], true); }

    public function isConflict(): bool
    { return $this->status === ContactResolutionStatus::Conflict; }
}

final class MetadataBag
{
    public function __construct(
        private AutomationStorage $storage,
        private string $scope,
        private string $scopeId,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    { return $this->storage->metadataGet($this->scope, $this->scopeId, $key, $default); }

    public function set(string $key, mixed $value): void
    { $this->storage->metadataSet($this->scope, $this->scopeId, $key, $value); }

    public function all(): array
    { return $this->storage->metadataAll($this->scope, $this->scopeId); }

    public function typed(string $class): object
    { return new $class($this); }
}

final readonly class ContactAlias
{
    public function __construct(
        public string $email,
        public ?string $name,
        public string $source,
    ) {}
}

final class Contact
{
    public string $name;
    public string $primaryEmail;
    /** @var list<ContactAlias> */
    public array $aliases;
    public readonly MetadataBag $metadata;

    public function __construct(
        public readonly string $id,
        private AutomationStorage $storage,
        string $name,
        string $primaryEmail,
        array $aliases,
    ) {
        $this->name = $name;
        $this->primaryEmail = $primaryEmail;
        $this->aliases = $aliases;
        $this->metadata = new MetadataBag($storage, 'contact', $id);
    }

    public function setName(string $name): void
    { $this->storage->contactSetName($this->id, $name); $this->name = $name; }

    public function addAlias(string $email, ?string $name = null, string $source = 'manual'): ContactAlias
    {
        $alias = $this->storage->contactAddAlias($this->id, $email, $name, $source);
        $this->refresh();
        return $alias;
    }

    public function setAliasName(string $email, ?string $name): void
    { $this->storage->contactSetAliasName($this->id, $email, $name); $this->refresh(); }

    public function setPrimaryEmail(string $email): void
    { $this->storage->contactSetPrimaryEmail($this->id, $email); $this->primaryEmail = strtolower($email); }

    public function removeAlias(string $email): void
    { $this->storage->contactRemoveAlias($this->id, $email); $this->refresh(); }

    private function refresh(): void
    {
        $fresh = $this->storage->contacts()->findById($this->id);
        if ($fresh === null) { throw new \RuntimeException('Contact disappeared.'); }
        $this->name = $fresh->name;
        $this->primaryEmail = $fresh->primaryEmail;
        $this->aliases = $fresh->aliases;
    }
}

final class Contacts
{
    public function __construct(private AutomationStorage $storage) {}

    public function findByEmail(string $email): ?Contact
    { return $this->storage->contactFindByEmail($email); }

    public function findById(string $id): ?Contact
    { return $this->storage->contactFindById($id); }

    public function create(string $email, ?string $name = null, string $source = 'manual'): Contact
    { return $this->storage->contactCreate($email, $name, $source); }
}

final class MailHistoryStore
{
    public function __construct(private AutomationStorage $storage) {}
    public function forContact(string $contactId): array
    { return $this->storage->historyForContact($contactId); }
}

final readonly class MailThread
{
    /** @param list<Email> $items */
    public function __construct(private array $items) {}
    public function messages(): array { return $this->items; }
}

final readonly class MailboxContext
{
    public Contacts $contacts;
    public MailHistoryStore $mailHistory;
    public MetadataBag $metadata;

    public function __construct(public MailClient $client, public AutomationStorage $storage)
    {
        $this->contacts = $storage->contacts();
        $this->mailHistory = new MailHistoryStore($storage);
        $this->metadata = new MetadataBag($storage, 'mailbox', $client->accountId());
    }
}

final class MailContext
{
    public readonly MetadataBag $metadata;
    public readonly MailboxContext $mailbox;
    public readonly MailThread $thread;
    /** @var array<string,Contact|null> */
    public array $recipientContacts = [];

    public function __construct(
        public readonly Email $mail,
        public readonly string $folder,
        public readonly string $direction,
        public readonly ?Contact $contact,
        public readonly ContactResolution $contactResolution,
        public PhoreLogger $logger,
        private AutomationStorage $storage,
        private MailClient $client,
    ) {
        $this->metadata = new MetadataBag($storage, 'message', $mail->id() ?? $mail->messageId() ?? spl_object_hash($mail));
        $this->mailbox = new MailboxContext($client, $storage);
        $this->thread = new MailThread([$mail]);
        if ($direction === 'outgoing') {
            foreach ([...$mail->to(), ...$mail->cc(), ...$mail->bcc()] as $address) {
                if ($client->fromAddress() !== null && $address->getAddress() === $client->fromAddress()->getAddress()) { continue; }
                $this->recipientContacts[$address->getAddress()] = $storage->contacts()->findByEmail($address->getAddress());
            }
        }
    }

    public function hasFlag(string $flag): bool
    { return in_array($flag, $this->mail->flags(), true); }

    public function createContactForRecipient(?string $email = null): Contact
    {
        if ($this->direction !== 'outgoing') { throw new \LogicException('Recipient contact creation is outgoing-only.'); }
        if ($email === null) {
            if (count($this->recipientContacts) !== 1) { throw new \InvalidArgumentException('Specify one actual external recipient.'); }
            $email = array_key_first($this->recipientContacts);
        }
        if (!array_key_exists($email, $this->recipientContacts)) { throw new \InvalidArgumentException('Recipient is not part of this outgoing message.'); }
        $known = $this->recipientContacts[$email];
        if ($known !== null) { return $known; }
        $contact = $this->storage->contacts()->create($email, source: 'sent_folder');
        $this->recipientContacts[$email] = $contact;
        return $contact;
    }
}
