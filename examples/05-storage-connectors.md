# Storage connectors

`MailAutomation` accepts either a SQLite file path, a SQLite `PDO`, or any object implementing `Lack\MailAutomation\AutomationStorage`.

## Existing SQLite file

```php
$automation = new MailAutomation(
    client: $client,
    storage: '/var/lib/app/application.sqlite',
);
```

The SQLite connector uses `CREATE TABLE IF NOT EXISTS`, so the file may already contain unrelated application tables. It creates or reuses only the automation tables it needs and leaves other tables untouched.

The built-in SQLite storage keeps these durable concerns:

| State | Why it is stored |
|---|---|
| account binding and folder cursors | resume synchronization safely and prevent accidental reuse for another mail account |
| contacts and aliases | keep learned sender identity across runs |
| metadata | persist application-owned state for messages, contacts and the mailbox |
| sent evidence | resolve replies against previously observed outgoing mail |
| mail history | retain the observed contact/mail relationship across runs |

## Custom connector

A custom backend implements the public `AutomationStorage` contract. The automation engine does not require SQLite when that interface is supplied. An application may also derive its own named interface from the package contract and let its connector implementation target that interface:

```php
use Lack\MailAutomation\AutomationStorage;
use Lack\MailAutomation\MailAutomation;

interface MyProjectMailStorage extends AutomationStorage
{
}

/** @var MyProjectMailStorage $storage */
$storage = $container->get(MyProjectMailStorage::class);

$automation = new MailAutomation(
    client: $client,
    storage: $storage,
);
```

The concrete connector must implement every method declared by `AutomationStorage` with equivalent persistence semantics: durable cursors, account binding, contacts and aliases, metadata, sent evidence and history. The connector owns persistence only. Mailbox synchronization, rule execution and processing semantics remain in `MailAutomation`; a connector should not duplicate IMAP behavior.
