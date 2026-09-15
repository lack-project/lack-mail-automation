# Lack Mail Automation

Stateful mail automation for PHP 8.5 on top of `phore/mail-client`. This package owns durable automation state, rules, contact identity, application metadata and processing semantics. The mail client remains the stateless IMAP boundary.

## Boundary

`phore/mail-client` supplies mailbox primitives: folder synchronization, configured standard folders, account/sender identity, side-effect-free reads, flags, drafts and verified same-account moves. `lack/mail-automation` consumes those APIs and adds SQLite-backed cursors, Sent evidence, contacts and aliases, metadata, tags, rule execution and the `phore_processed` processing gate. No IMAP transport implementation is duplicated here.

During development this package depends on `phore/mail-client:dev-feat/folder-sync`; after the corresponding MailClient PR is merged, switch the constraint to the released version containing these primitives.

## Basic run

```php
<?php
use Lack\MailAutomation\Folder;
use Lack\MailAutomation\MailActions;
use Lack\MailAutomation\MailAutomation;
use Phore\MailClient\Email;

$database = new PDO('sqlite:/var/lib/app/mail-automation.sqlite');
$automation = new MailAutomation(client: $client, storage: $database);

$automation->onFolder(Folder::Inbox)->addAutomation(
    matches: fn (Email $mail, $context): bool => true,
    handle: fn (Email $mail, $context): MailActions =>
        MailActions::create()->moveTo('Customers'),
);

$report = $automation->run();
```

The same SQLite database must be reused across runs. Tables for cursors, contacts, aliases, metadata, tags, Sent evidence and history are initialized automatically. State is bound to one MailClient account ID; reusing it with another account fails.

## Processing model

`run()` synchronizes the configured Sent folder first, then every folder with registered rules. It uses `MailClient::syncFolder()` and persists the returned cursor only after the observed batch has been processed. On the first Sent scan, existing messages are indexed as reply evidence and marked `phore_processed` without running outgoing business rules by default. Pass `processExistingOutgoing: true` to opt into those rules for existing unmarked Sent mail.

`phore_processed` is the global gate. Marked messages skip contact learning, predicates and handlers. Successful actions, `MailActions::complete()` and an exhausted chain all mark the concrete message processed. `MailActions::pass()` delegates to the next rule without marking it. Generic actions cannot add or remove the reserved processing marker.

`moveTo($folder, reprocess: true)` deliberately hands the moved message to the target folder on a later run. The target must have a registered chain. The engine does not execute the destination chain in the same run.

## Rules

Use `onFolder(Folder|string)->addAutomation()` for programmatic rules. Higher priority runs first; ties keep registration order. `matches=false` skips a rule. Exceptions stop processing for the message and are exposed through `RunReport::errors()`.

Attributed functions and public methods can use `#[OnFolderAutomation(...)]` from `Lack\MailAutomation\Attributes`. `addRules()` compiles these into the same rule model. `active: false` disables only that rule. `flag:` requires the current IMAP keyword in addition to the generated match.

## Contacts and reply evidence

`ReplyContactResolver` is the default. A known From address resolves immediately. For a new address replying with `In-Reply-To`, cached Sent evidence is not sufficient: the referenced Sent message is verified live through `MailClient::peek()` before a contact or alias is learned.

The six outcomes are:

| Status | Meaning |
|---|---|
| `Unknown` | No known address and no usable reply evidence. |
| `KnownAddress` | From already belongs to a contact. |
| `ContactCreated` | First verified reply created the recipient contact; a differing From becomes an alias. |
| `AliasAdded` | Verified reply added a new From alias to an existing recipient contact. |
| `Conflict` | Evidence contradicts an existing contact assignment; nothing is merged. |
| `OutgoingMissing` | Referenced Sent evidence is no longer present for live verification. |

`ContactResolution::needsReview()` is true for `Conflict` and `OutgoingMissing`. Contact mutations (`setName`, `addAlias`, `setAliasName`, `setPrimaryEmail`, `removeAlias`) persist immediately. An address can belong to only one contact, and the primary address cannot be removed. `Contacts` is iterable, so maintenance and reporting code can loop over all known contacts directly.

## Tags

Contacts, the currently processed message and stored mail-history entries expose the same tag operations:

```php
$contact->setTag('customer');
$contact->setTag('customer_group', 'a');
$contact->hasTag('customer_group', 'a');
$contact->getTagValue('customer_group');
$contact->removeTag('customer_group');
$contact->tags();
```

The value is optional. One tag name exists at most once per tagged object; calling `setTag()` again replaces its previous value. `hasTag($name)` checks only existence, while `hasTag($name, $value)` also requires that value. `getTagValue()` returns `null` both for a valueless tag and an absent tag, so use `hasTag()` when the distinction matters.

Inside an automation, `MailContext` offers `setTag()`, `hasTag()`, `getTagValue()`, `removeTag()` and `tags()` for the current message. Message tags are independent from contact tags and persist immediately.

## Mail history

`Contact::mailHistory(int $limit = 0, HistoryFilter ...$filters)` streams matching history entries lazily. `limit=0` means unlimited; a positive limit counts matched entries. Mail history is a public ordering contract: it is always returned in descending chronological order, newest mail first. Filters never change that order.

Built-in filters include `HistoryFilter::tag()`, `HistoryFilter::subjectContains()`, `HistoryFilter::from()` and `HistoryFilter::to()`. Multiple filters use AND semantics. `HistoryFilter::callback()` supports application-specific decisions and must return `HistoryFilterResult::Match`, `NoMatch` or `Stop`. `Stop` terminates the generator immediately, so the SQLite cursor is not drained further. The built-in `from()` filter uses this because once newest-first history reaches an entry older than the lower bound, all later rows are older as well.

```php
foreach (
    $contact->mailHistory(
        10,
        HistoryFilter::tag('newsletter'),
        HistoryFilter::from(new DateTimeImmutable('2026-01-01')),
    ) as $entry
) {
    // Newest matching newsletter first.
}
```

During an incoming handler, `Contact::mailHistory()` contains the previously recorded history. The current message is recorded after its handler finishes; tags set through `MailContext` are then visible on that new history entry.

## Metadata scopes

Four application-owned scopes are available as independent `MetadataBag`s: `MailContext::metadata` for the current message, `Contact::metadata`, `MailboxContext::metadata` and contact history through `MailboxContext::mailHistory`. Metadata never changes processing or contact resolution unless an application rule explicitly reads it.

`MetadataBag::typed(MyMetadata::class)` constructs a typed application wrapper with the bag as its constructor argument; there is no reflection-based inference beyond that explicit class choice.

## Actions and reports

`MailActions::create()` can add/remove ordinary keywords, move the current message and request a reply through an application-provided `DraftSender`. `MailActions::complete()` finishes without mail actions, and `MailActions::pass()` delegates.

`RunReport` exposes `processed`, `skipped`, `indexedSent`, `successful()` and `errors()`. Each `RunError` contains the folder, message ID when known and original exception.

## Examples

Read in order:

1. `examples/01-overview.php` — complete routing run.
2. `examples/02-contact-resolution.php` — handle all contact-resolution outcomes.
3. `examples/03-contact-management.php` — deliberate contact and alias maintenance.
4. `examples/04-attributes-and-metadata.php` — attributes and typed application metadata.
5. `examples/05-storage-connectors.md` — storage connector notes.
6. `examples/06-contact-tags-and-history.php` — contact/message tags, lazy filtered history and contact iteration.

## Limits

One `MailAutomation` instance processes one MailClient account at a time. There is no scheduler, SMTP implementation, cross-account move, distributed lock or exactly-once guarantee. The engine is intended for serialized runs; handlers should remain idempotent around external side effects.
