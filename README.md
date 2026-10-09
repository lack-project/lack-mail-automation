# Lack Mail Automation

AI-native mailbox automation on top of `phore/mail-client` and
`phore/ai-harness`.

The normal application surface is one `MailAutomation` object. Connection,
storage, locking, Sent/Inbox synchronization, automation flags, analysis and
action discovery belong to the library rather than to application `run.php`
files.

## Bootstrap

```php
<?php

use Lack\MailAutomation\MailAutomation;
use Lack\MailAutomation\MailAutomationConfig;

return MailAutomation::fromConfig(
    new MailAutomationConfig(
        mailbox: dirname(__DIR__) . '/mailclient.yaml',
        actionsDirectory: __DIR__ . '/actions',
    ),
);
```

Mailbox folders and the `processed`, `error` and `actionRequired` flags are
configured in the existing `phore/mail-client` mailbox YAML/JSON.

## CLI

The package installs `vendor/bin/lack-mail-automation`:

```bash
vendor/bin/lack-mail-automation run
vendor/bin/lack-mail-automation run --dry-run
vendor/bin/lack-mail-automation run --mail-id '<mail-client-id>'
```

The default bootstrap is `automail/bootstrap.php`; use `--bootstrap` only
when the application keeps it elsewhere.

A normal run observes Sent before Inbox, then any additional folders required
by registered actions. The process lock, cursor handling and status flags are
internal. `--mail-id` explicitly retries one message without advancing a
folder cursor.

## Actions

Actions are discovered recursively from `actionsDirectory`:

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

Higher numeric `priority` only resolves overlap between otherwise matching
conditions.

Generated outbound content is `AiMailDraft extends AiMail`. Drafts provide
`setRecipient()`, `setSubject()`, `setText()`, `attach()`, `draft()`
and `send()`; Reply recipients/subjects remain derived from their source mail
to preserve threading.
