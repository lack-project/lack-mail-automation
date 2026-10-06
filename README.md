# Lack Mail Automation

AI-native, stateful mail automation for PHP 8.5 on top of
`phore/mail-client` and `phore/ai-harness`.

The normal application entry point is `MailAutomation`. It owns the mailbox
cycle, durable state and AI-routed actions. A standard run always observes
**Sent first and Inbox second**, so action selection sees the latest outgoing
conversation state before processing new inbound mail.

## Minimal setup

```php
<?php

use Lack\MailAutomation\Analysis\MailAnalyzer;
use Lack\MailAutomation\Folder;
use Lack\MailAutomation\MailAutomation;
use Phore\MailClient\MailboxConfig;

$client = MailboxConfig::fromFile($argv[1])->connect();

$automation = new MailAutomation(
    client: $client,
    storage: $argv[2],
    inboxFolder: Folder::Inbox,
    sentFolder: Folder::Sent,
    mailAnalyzer: new MailAnalyzer(),
);

$automation->scanAutomations(
    __DIR__ . '/actions',
    '*Action.php',
);

$report = $automation->run();
```

`scanAutomations()` recursively loads matching files and registers every
concrete class carrying `#[OnMailAction(...)]`. For dependency-injected actions,
use `addMailAutomation()` or `addMailActions()` explicitly instead.

## AiMail and AiMailDraft

`MailAnalyzer` returns `AiMail`. It represents an observed mailbox message and
is itself AI Harness `AiContent`: it has an ID, aliases, instructions, the
conversation summary, previous mails and current/historical attachments.

AI generation returns `AiMailDraft`. A draft **extends `AiMail`**, so it
retains the same AI-content capabilities, but only the draft type exposes
outbound mutation/delivery operations:

```php
$draft = $mail->ai_reply($prompt)
    ->setSubject('Re: Lebenslauf')
    ->attach($pdf);

return $draft->send();
```

Available draft operations include `setRecipient()`, `setSubject()`,
`setText()`, `attach()`, `draft()` and `send()`. The setters are fluent
and return a new typed draft.

Sending does not directly become conversation history. The draft identity
(ID, aliases and instructions) is persisted as pending outbound metadata. The
authoritative message enters history when the Sent folder is scanned. The
linker prefers an exact recipient/subject/body match and accepts a unique
recipient/subject match so manual body edits can still retain the draft
identity.

## AI-routed actions

```php
#[OnMailAction(
    condition: 'This is the first applicant reply and required CV data is missing.',
    priority: 100,
)]
final class RequestCvDetailsAction
{
    public function __invoke(AiMail $mail): MailAction
    {
        return $mail->ai_reply(
            new PromptFile(__DIR__ . '/request-details.md'),
        )->send();
    }
}
```

`condition` is the complete semantic routing rule. `priority` is only a
tie-breaker: a higher number wins when several conditions fit equally well; it
never makes a non-matching action valid.

## Manual cycle control

The standard `run()` order is fixed. Integrations that need explicit control
can call `scanSent()` and `scanInbox()` separately. This is useful for
maintenance jobs and tests, not for normal application boilerplate.

## Advanced APIs

Legacy folder rules, contacts, tags, metadata bags, manual flags, managed-folder
moves and filtered contact history remain available for specialized workflows.
They are not required for the normal conversation-driven AI action flow.

See the numbered `examples/` directory for the recommended API sequence.
