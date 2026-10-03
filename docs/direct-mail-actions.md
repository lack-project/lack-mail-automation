# Direct content-driven mail automation

This is the preferred integration for semantic actions. The explicit matcher
and legacy `OnFolderAutomation` APIs remain supported for low-level integrations.

```php
$automation = new MailAutomation(
    client: $client,
    storage: '/var/lib/app/mail-automation.sqlite',
    mailAnalyzer: new MailAnalyzer(
        mailClasses: MailKind::class,
        attachmentClasses: AttachmentKind::class,
    ),
);
$automation->addMailActions(
    [new LeadActions(), new ProfileActions()],
    flagUnhandled: true,
);
$report = $automation->run();
```

Each object declares public methods receiving `(AnalyzedMail, MailContext)` and
returning `MailAction`:

```php
#[OnMailAction(
    condition: 'The applicant explicitly requests a correction to their existing profile.',
    id: 'profile-update',
    when: 'hasExistingProfile',
    folder: Folder::Inbox,
)]
public function updateProfile(AnalyzedMail $mail, MailContext $context): MailAction
{
    // Application code validates and prepares the document before scheduling.
    return $mail->reply('The revised draft is attached.', [$document]);
}
```

`folder` defaults to `Folder::Inbox`. An enum resolves a configured standard
folder. A string is an exact IMAP source folder name, **not** a managed-folder
alias or another account. Moves still use managed aliases through `moveTo()`.
One `MailAutomation` handles one connected mailbox account.

All objects passed in one `addMailActions()` call form one shared choice set.
The wrapper registers the required physical folders once, filters choices by
the actual folder **before** invoking eligibility guards or AI, and dispatches
at most one handler per message. IDs must be unique across that group. Use
explicit different `automationId` values only for deliberately separate groups;
separate groups do not compete in one AI request. Legacy rule priority applies.
A missing analyzer, duplicate ID, invalid attribute or duplicate group fails
at registration. A failed group never leaves partially registered folder rules.

The optional `when` method returns `bool` and must be side-effect-free. Use it
for exact sender filters, real contact/profile existence and application
authorization, not semantic business tags. A matching From header is not proof
of sender identity: enforce sender authentication at the receiving boundary.

The matcher consumes only the dated conversation summaries, metadata and
attachment summaries. Handlers may load complete text and original files after
selection. No match, incomplete analysis, unavailable referenced history,
conflicting evidence or excessive context abstains. `flagUnhandled: true`
requests human review; the default passes without changing mail/action metadata.
Dry-run still analyzes/selects but does not invoke business handlers or execute
scheduled actions. Existing contact/Sent bookkeeping can still write to local
storage; use separate state for an isolated preview.

## Initial messages versus replies

`$mail->reply()` / `sendReply()` reply to the original message. A forwarded lead
usually needs an independent message to a validated applicant address:

```php
return MailActions::schedule()
    ->sendMail((new Email(to: $validatedApplicant, subject: 'Welcome'))->withMarkdown($body))
    ->moveTo('processed_leads');
```

`sendMail()` queues that exact `Email` (including its attachments). It does not
choose recipients through AI or reply to the forwarder. Like `sendReply()`, it
saves a draft unless `MailAutomation` has an explicit `DraftSender`. With a
sender, the original is marked Answered after successful sending when enabled
by the MailClient configuration. Dry-run executes neither operation. Technical
processed/error/actionRequired flags retain their existing behavior.

Sending and subsequent flagging/moving are not an exactly-once transaction.
Serialize runs and use application delivery reservations/receipts; ambiguous
outcomes require review rather than blind retries. Existing history must be
indexed explicitly when enabling analysis on an old persistent cursor.

See `examples/07-ai-classification.php` for complete initialization and
`test/AiMailActionsTest.php` for folder/registration/dry-run/send contracts.
