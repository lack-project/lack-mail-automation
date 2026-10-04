# Mail content actions

\`MailAnalyzer\` returns \`MailContent\`. \`MailContent\` extends the AI Harness
\`AiContent\` abstraction and owns the conversation context used for action
matching and later business work.

The context is prepared in this order:

1. a compact chronological conversation summary and attachment index;
2. complete historic mail bodies as \`AiText\`;
3. supported current and historic attachments as the matching \`AiDocument\`
   specialization such as \`AiImage\`, \`AiMarkdown\` or \`AiText\`;
4. the complete current mail body as the \`MailContent\` source itself.

This keeps the summary as the normal starting point while full source material
remains available in the same context.

## One class per action

Each business action is an invokable class with \`#[OnMailAction]\`. Put routing
semantics into \`condition\`; deterministic \`when\` guards remain available as
an optional library feature but are not required for normal AI-routed
workflows.

\`\`\`php
#[OnMailAction(
    condition: 'The applicant asks to create or update the profile and that request is still open.',
)]
final class ProfileAction
{
    public function __invoke(MailContent $mail, MailContext $context): MailAction
    {
        return $mail->ai_reply(
            new PromptFile(__DIR__ . '/_prompts/reply.md'),
        );
    }
}
\`\`\`

Register the cooperating actions once:

\`\`\`php
$automation->addMailActions([
    new MetaLeadAction(),
    new ApplicantReplyAction(),
    new ProfileAction(),
]);
\`\`\`

The matcher evaluates all conditions against the bound \`MailContent\` context
and selects at most one action.

## Action directory notation

Applications should keep actions in numeric ten-step directories. This leaves
room for inserting a new step without renaming all existing directories. The
action PHP file is directly inside its step directory. Prompt files live in
\`_prompts\`; the leading underscore marks support data that is not part of the
PHP namespace.

\`\`\`text
automail/
└── bewerber/
    ├── 10-meta-lead/
    │   ├── MetaLeadAction.php
    │   └── _prompts/
    │       └── action.md
    ├── 20-applicant-reply/
    │   ├── ApplicantReplyAction.php
    │   └── _prompts/
    │       └── action.md
    ├── 30-profile/
    │   ├── ProfileAction.php
    │   └── _prompts/
    │       └── action.md
    └── 40-cv/
        ├── CvAction.php
        └── _prompts/
            └── action.md
\`\`\`

Use Composer classmap autoloading when the numeric directory names intentionally
do not mirror PHP namespaces.

## Attachments are AI content

\`getAttachments()\` returns AI Harness \`AiDocument\` objects. The analyzer
factory returns specializations such as \`AiImage\`, \`AiMarkdown\` or
\`AiText\` where possible.

\`\`\`php
foreach ($mail->getAttachments() as $document) {
    $text = $document->extractText();
}
\`\`\`

The complete mail context can also select content by meaning:

\`\`\`php
$images = $mail->ai_query_content(
    'Which images show the applicant and are suitable as a profile photo?',
);

$image = $images->first();
\`\`\`

Historic attachment bytes are loaded into the same content registry when the
backing message is available. Unsupported MIME types remain generic
\`AiDocument\` objects for storage or forwarding but are not sent to the AI
context.

## Mail generation

Mail-specific operations build on the same context:

\`\`\`php
return $mail->ai_reply(
    new PromptFile(__DIR__ . '/_prompts/reply.md'),
);
\`\`\`

\`ai_mail()\` creates a new mail. A fixed recipient can be supplied by the
application, or \`null\` lets the trusted business prompt derive the recipient
from the conversation. The resulting address is syntax-validated before the
action is scheduled.

\`\`\`php
return $mail->ai_mail(
    null,
    new PromptFile(__DIR__ . '/_prompts/initial-contact.md'),
);
\`\`\`

\`ai_forward()\` creates a forward-style mail with an explicit recipient and
can optionally copy current attachments.

Direct \`reply()\` attachments are also \`AiDocument\` objects, so generated
\`AiMarkdown\`, \`AiImage\` or other document types can be sent without
converting them to mail-client attachments in application code.

## Conversation scope

\`scope()\` returns the thread scope. \`scopeFor($id)\` creates an application
scope spanning several threads, for example \`applicant:<email>\`.

The default \`ConversationStore\` persists metadata and file bytes in the same
SQLite-backed \`AutomationStorage\`. Stored files are application state; AI
processing of file content should use the AI Harness content classes.
