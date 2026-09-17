<?php
use Lack\MailAutomation\Attributes\OnFolderAutomation;
use Lack\MailAutomation\Folder;
use Lack\MailAutomation\HistoryFilter;
use Lack\MailAutomation\MailAction;
use Lack\MailAutomation\MailActions;
use Lack\MailAutomation\MailContext;
use Phore\MailClient\Email;

#[OnFolderAutomation(folder: Folder::Inbox)]
function handleCustomerHistory(Email $mail, MailContext $context): MailAction
{
    if ($context->contact === null) {
        return MailActions::pass();
    }

    $contact = $context->contact;

    $contact->setTag('customer');
    $contact->setTag('customer_group', 'a');

    if ($contact->hasTag('customer_group', 'a')) {
        $group = $contact->getTagValue('customer_group');
        $context->logger->debug('Resolved customer group {}', [$group]);
    }

    // Message tags are independent from contact tags. They persist immediately and become
    // visible on the history entry that is recorded after this message finishes processing.
    $context->setTag('newsletter', '2026-09');
    $context->setTag('temporary');
    $context->removeTag('temporary');

    // History is streamed newest-first. Inside a handler this contains previous messages;
    // the current message is appended to history after the handler completes successfully.
    $previousNewsletter = null;
    foreach ($contact->mailHistory(1, HistoryFilter::tag('newsletter')) as $entry) {
        $previousNewsletter = $entry;
    }

    if ($previousNewsletter !== null) {
        $context->logger->debug('Previous newsletter was {}', [$previousNewsletter->sortDate()->format(DATE_ATOM)]);
    }

    return MailActions::complete();
}

$automation->addRules('handleCustomerHistory');
$automation->run();

// Contacts are iterable for maintenance/reporting jobs outside an individual message handler.
foreach ($automation->storage()->contacts() as $contact) {
    foreach (
        $contact->mailHistory(
            10,
            HistoryFilter::tag('newsletter'),
            HistoryFilter::subjectContains('Newsletter'),
            HistoryFilter::from(new DateTimeImmutable('2026-01-01')),
        ) as $entry
    ) {
        echo $contact->primaryEmail . ' | ' . $entry->sortDate()->format('Y-m-d') . ' | ' . $entry->subject . PHP_EOL;
    }
}
