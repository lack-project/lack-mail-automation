<?php

declare(strict_types=1);

use Lack\MailAutomation\Analysis\AnalyzedMail;
use Lack\MailAutomation\Analysis\MailAnalyzer;
use Lack\MailAutomation\Attributes\OnMailAction;
use Lack\MailAutomation\Folder;
use Lack\MailAutomation\MailAction;
use Lack\MailAutomation\MailAutomation;
use Lack\MailAutomation\MailContext;
use Phore\MailClient\Attachment;
use Phore\MailClient\MailboxConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

// php examples/07-ai-classification.php /path/to/test-mailbox.yaml /path/to/demo.sqlite
// Configure API credentials through phore/ai-harness. Use a separate test account.
enum DocumentKind: string
{
    case Document = 'document';
    case Photo = 'photo';
}

final class DocumentActions
{
    public function oneDocument(AnalyzedMail $mail, MailContext $context): bool
    {
        return count($mail->getAttachments(DocumentKind::Document)) === 1;
    }

    #[OnMailAction(
        condition: 'The sender explicitly asks for a text version of the attached document, and no later reply already fulfilled this request.',
        when: 'oneDocument',
        folder: Folder::Inbox,
    )]
    public function provideText(AnalyzedMail $mail, MailContext $context): MailAction
    {
        $document = $mail->getAttachments(DocumentKind::Document)[0];
        $text = $document->getContent();       // Full extract, not the summary.
        $summary = $document->getSummary();   // Cached, no additional AI call.
        $rawFile = $document->getRawFile();   // Keep the PhoreTempFile alive while in use.
        $rawBytes = $rawFile->get_contents(); // Exact original bytes.

        return $mail->reply('Here is the extracted text. Please verify it against the original document.', [
            Attachment::fromBytes('document.txt', 'text/plain', $text),
        ]);
    }
}

$client = MailboxConfig::fromFile($argv[1] ?? throw new InvalidArgumentException('Pass a test mailbox YAML path.'))->connect();
$automation = new MailAutomation(
    client: $client,
    storage: $argv[2] ?? throw new InvalidArgumentException('Pass a separate demo SQLite path.'),
    mailAnalyzer: new MailAnalyzer(
        mailClasses: ['text_request' => 'Request for an attachment as text', 'other' => 'Other correspondence'],
        attachmentClasses: DocumentKind::class,
    ),
);
// No OnFolderAutomation adapter or manual forwarding method is necessary.
// Pass [new LeadActions(), new ProfileActions()] to share choices across classes.
$automation->addMailActions(new DocumentActions());

// Preview selects only: no business handler, scheduled action, draft or send.
// run(dryRun: false) saves replies as drafts unless an explicit DraftSender is supplied.
$report = $automation->run(dryRun: true);
$automation->logger()->result('Preview finished: skipped={}, errors={}', [$report->skipped, count($report->errors())]);
