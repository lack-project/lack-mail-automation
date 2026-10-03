# Direct mail actions

The content-driven API has no separate mail or attachment classification type
system. `MailAnalyzer` extracts mail/attachment content and stores the observed
conversation. `OnMailAction` decides what to do next.

## One class per action

Prefer one invokable class for each business action:

```php
#[OnMailAction(
    condition: 'The applicant asks to change the existing profile and this request is still open.',
)]
final class ChangeProfile
{
    public function __invoke(AnalyzedMail $mail, MailContext $context): MailAction
    {
        $scope = $mail->scopeFor('applicant:' . strtolower($mail->from[0]));
        $profile = $scope->getFile('profile.md');

        // generate the new profile from mail->routingContext() and the stored file
        $scope->putFile('profile.md', $newProfile, 'text/markdown');

        return $mail->reply('The updated profile is attached.');
    }
}
```

Register all cooperating actions once:

```php
$automation = new MailAutomation(
    client: $client,
    storage: '/var/lib/app/mail.sqlite',
    mailAnalyzer: new MailAnalyzer(aiOptions: $aiOptions),
);

$automation->addMailActions([
    new InitialContact(),
    new FirstReply(),
    new ChangeProfile(),
]);
```

## `when` before AI

`when` is only a deterministic pre-filter. It runs before any AI choice.
A same-object method name remains supported. For class-based actions prefer a
static callable:

```php
#[OnMailAction(
    when: [MetaLead::class, 'isSource'],
    condition: 'The message contains only applicant data for a new lead.',
)]
final class MetaLead
{
    public static function isSource(AnalyzedMail $mail, MailContext $context): bool
    {
        return $mail->from === ['forwarder@example.org'];
    }
}
```

PHP attributes cannot contain closures. A static callable array is the direct,
constant-expression equivalent. If `condition` is omitted, a successful
`when` selects that action without an AI call. More than one matching
guard-only action is treated as an ambiguous configuration.

## Conversation is the state

`routingContext()` contains the complete observed mail bodies in chronological
order, attachment summaries, missing-reference information, and a snapshot of
the conversation scope. The matcher asks the configured conditions against that
conversation instead of requiring application tags such as "profile created" or
"first reply".

There is intentionally no `MailType`, attachment enum, or classification
result in the analyzer API.

## Metadata and files

Every analyzed mail exposes a default thread scope:

```php
$scope = $mail->scope();

$scope->set('note', 'manual review complete');
$scope->get('note');

$scope->putFile('profile.md', $markdown, 'text/markdown');
$scope->hasFile('profile.md');
$scope->fileModifiedAt('profile.md');
$scope->getFile('profile.md')?->content;
$scope->files();
```

Applications can deliberately create a stable recipient/customer scope that
spans several mail threads:

```php
$scope = $mail->scopeFor('applicant:' . strtolower($mail->from[0]));
```

`ConversationStore` is the persistence interface. By default
`AutomationStorageConversationStore` stores metadata and file bytes through the
existing `AutomationStorage`; with the standard `SqliteStorage` this means the
same SQLite database. An application can pass another `ConversationStore` to
`MailAnalyzer` when data belongs in another backend.

## Attachments

Attachments are analyzed but not classified. Each attachment has a stable ID,
filename, media type, summary, extracted content, original bytes, and
`getRawFile()`. If an action needs to find the CV, it can make that decision
from the attachment summaries/full content after the action itself has been
selected.

Technical processing flags (`processed`, `error`, `actionRequired`) remain
engine mechanics. They are not semantic conversation state.


## Schema-backed mail prompts

Consumers should not rebuild mail arrays with `StructPrompt`. LACK exposes
schema-backed factories that return native AI Harness `StructPrompt` objects:

```php
$mailPrompt = $mail->prompt(alias: 'incomingEmail');
$conversationPrompt = $mail->conversationPrompt(alias: 'conversation');

$result = phore_ai_struct([
    new PromptFile(__DIR__ . '/_prompt/action.md'),
    $conversationPrompt,
], Result::class, $aiOptions);
```

The schema is generated from LACK's internal prompt DTOs through the normal
`phore/schema` integration used by `StructPrompt`. Mail and conversation
content remain untrusted by default. `allowInstructions: true` must be an
explicit application decision.

For reusable long-form reply generation, use an external Markdown prompt and
`createResponseMail()`:

```php
return $mail->createResponseMail(
    new PromptFile(__DIR__ . '/_prompt/answer-question.md'),
    $aiOptions,
);
```

The method appends the complete `conversation` prompt automatically and
returns either a scheduled reply or `actionRequired()` when the model reports
that the available context is insufficient. An optional callback can create
reply attachments after a usable draft was generated.

Short one-line tasks such as a simple classification may stay inline. Prompts
for composing mails, profiles, CV revisions or other growing business content
should live in dedicated Markdown files next to the action that owns them.
