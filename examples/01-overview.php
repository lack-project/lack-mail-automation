<?php
use Lack\MailAutomation\Attributes\OnFolderAutomation;
use Lack\MailAutomation\Folder;
use Lack\MailAutomation\MailActions;
use Lack\MailAutomation\MailAutomation;
use Lack\MailAutomation\MailContext;
use Phore\MailClient\Email;

// $client is one connected MailClient with From/Inbox/Sent folders configured.
// Its mailbox config defines managedFolders aliases `review` and `customers`;
// MailClient checks/creates those target folders when the client starts.
// Reuse this SQLite file on every run so cursors, contacts and evidence survive.
$automation = new MailAutomation(
    client: $client,
    storage: '/var/lib/app/application.sqlite',
);

final class OverviewRules
{
    #[OnFolderAutomation(Folder::Inbox, priority: 100)]
    public function review(Email $mail, MailContext $context): MailActions
    {
        if (!$context->contactResolution->needsReview()) {
            return MailActions::pass();
        }
        $context->logger->notice('Route message {} to review', [$mail->messageId() ?? $mail->id() ?? 'unknown']);
        return MailActions::create()->addFlag('phore_review')->moveTo('review');
    }

    #[OnFolderAutomation(Folder::Inbox)]
    public function customer(Email $mail, MailContext $context): MailActions
    {
        $context->logger->debug('Route message {} to customers', [$mail->messageId() ?? $mail->id() ?? 'unknown']);
        return MailActions::create()->moveTo('customers');
    }
}

$automation->addRules(new OverviewRules());
$report = $automation->run();

// Example result: a known sender is moved through the `customers` alias and marked phore_processed.
// A conflicting/missing reply reference is moved through `review` with phore_review.
// Persisted sync cursors advance only after the observed batch is processed.
