# AI-routed mail actions

## 1. Start with MailAutomation

`MailAutomation` is the facade. It owns the connection, durable storage,
action registration and the mailbox cycle.

```php
$automation = new MailAutomation(
    client: $client,
    storage: '/var/lib/app/mail.sqlite',
    inboxFolder: Folder::Inbox,
    sentFolder: Folder::Sent,
    mailAnalyzer: new MailAnalyzer(aiOptions: $aiOptions),
);

$automation->scanAutomations(
    __DIR__ . '/actions',
    '*Action.php',
    aiOptions: $aiOptions,
);

$automation->run();
```

The standard run order is always Sent, then Inbox. Outgoing messages become
conversation state only when observed in Sent.

## 2. Declare actions

```php
#[OnMailAction(
    condition: 'This is the first reply to our initial applicant mail.',
    priority: 50,
)]
final class RequestDetailsAction
{
    public function __invoke(AiMail $mail): MailAction
    {
        return $mail->ai_reply(
            new PromptFile(__DIR__ . '/request-details.md'),
        )->send();
    }
}
```

Higher `priority` means stronger precedence only when several complete
conditions match.

## 3. Work with drafts

Observed messages are `AiMail`. Generated outbound mail is `AiMailDraft`,
which extends `AiMail`.

```php
$draft = $mail->ai_forward(
    'office@example.org',
    new PromptFile(__DIR__ . '/forward.md'),
);

$draft = $draft
    ->setSubject('New CV')
    ->attach($pdf);

return $draft->send();
```

Use `draft()` instead of `send()` to save without delivery. A draft keeps AI
content identity and can still be queried or referenced before delivery.

## 4. Sent is authoritative

Before delivery, the automation stores the draft's ID, aliases and instructions
as pending metadata. On the next Sent scan, the final outgoing message is
analyzed and that identity is attached to the observed `AiMail`. This avoids
maintaining a second conversation history and includes mail changed manually
before sending.

## 5. Manual registration and scanning

Dependency-injected actions can be registered explicitly:

```php
$automation->addMailAutomation(new RequestDetailsAction($service));
```

Manual maintenance flows can synchronize the folders separately:

```php
$automation->scanSent();
$automation->scanInbox();
```

Normal application code should prefer `run()`.
