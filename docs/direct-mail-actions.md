# AI-routed mail actions

`MailAnalyzer` turns an incoming message into `MailContent`. The result is
itself an AI Harness `AiContent` block and carries the complete prepared
conversation context. Application code therefore does not need a second
classification layer.

## 1. Declare business actions

Each business action is one invokable class. Its `OnMailAction` condition says
in natural language when the class should run. The AI matcher evaluates all
conditions against the same mail and conversation context and selects at most
one action.

```php
#[OnMailAction(
    condition: 'This is the first applicant reply to our initial contact.',
)]
final class RequestDetails
{
    public function __invoke(MailContent $mail): MailAction
    {
        return $mail->ai_reply(
            new PromptFile(__DIR__ . '/_prompts/request-details.md'),
        )->send();
    }
}
```

No private matcher helpers or deterministic `when` guards are required for
normal semantic routing.

## 2. Register and run

```php
$automation = new MailAutomation(
    client: $client,
    storage: '/var/lib/app/mail.sqlite',
    mailAnalyzer: new MailAnalyzer(aiOptions: $aiOptions),
);

$automation->addMailActions([
    new InitialContact(),
    new RequestDetails(),
    new BuildCvDraft(),
    new ForwardApprovedCv(),
]);

$automation->run();
```

The engine analyzes each new mail first, builds its thread context, asks the AI
which declared condition matches and invokes the selected class with that
`MailContent`.

## 3. Work directly with the AI mail

The current mail has an ID, aliases and handling instructions just like every
other AI Harness content block:

```php
$mail->getId();
$mail->getAliases();
$mail->getInstructions();
```

The prepared context contains the chronological summary, complete historical
mail bodies, supported current and historical attachments and the complete
current mail body.

Use `ai_query_content()` when an action needs a particular document:

```php
$pdf = $mail->ai_query_content(
    'Select the latest CV PDF that we sent to the applicant.',
)->first();
```

## 4. Create, reply and forward

`ai_mail()`, `ai_reply()` and `ai_forward()` return an `AiMail` content block. Only `send()` schedules delivery. A new mail can derive its recipient from the conversation:

```php
return $mail->ai_mail([
    new PromptFile(__DIR__ . '/_prompts/initial.md'),
    'Use the applicant email address and salutation from the lead mail.',
        ])->send();
```

A reply keeps the current thread:

```php
return $mail->ai_reply(
    new PromptFile(__DIR__ . '/_prompts/review.md'),
    attachments: [$pdf],
        )->send();
```

A forward has an explicit recipient while body and subject can still be
generated from the conversation:

```php
return $mail->ai_forward(
    'office@example.org',
    new PromptFile(__DIR__ . '/_prompts/forward.md'),
    subject: 'Approved CV',
)->attach($pdf)->send();
```

## 5. Extra persistent state

The conversation is the normal business state. `scope()` and
`scopeFor($id)` remain available when an application deliberately needs
additional persistent metadata or files. The default store uses the same
SQLite-backed automation storage.

Tags, metadata bags, contacts, manual flags and legacy folder rules are
special-purpose APIs. They remain implemented, but they are not required for
the standard AI-routed workflow.
