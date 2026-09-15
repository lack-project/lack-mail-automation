<?php
use Lack\MailAutomation\Folder;
use Lack\MailAutomation\MailActions;
use Lack\MailAutomation\MailAutomation;
use Phore\MailClient\Email;

// $client is one connected MailClient with From/Inbox/Sent folders configured.
// Reuse this SQLite file on every run so cursors, contacts and evidence survive.
// The file may already contain application tables; MailAutomation only creates
// its own state tables when they are missing and leaves unrelated tables intact.
$automation = new MailAutomation(
    client: $client,
    storage: '/var/lib/app/application.sqlite',
);

$automation->onFolder(Folder::Inbox)->addAutomation(
    priority: 100,
    matches: fn (Email $mail, $context): bool => $context->contactResolution->needsReview(),
    handle: fn (Email $mail, $context): MailActions =>
        MailActions::create()->addFlag('phore_review')->moveTo('Review'),
);

$automation->onFolder(Folder::Inbox)->addAutomation(
    matches: fn (Email $mail, $context): bool => true,
    handle: fn (Email $mail, $context): MailActions =>
        MailActions::create()->moveTo('Customers'),
);

$report = $automation->run();

// Example result: a known sender is moved to Customers and marked phore_processed.
// A conflicting/missing reply reference is moved to Review with phore_review.
// Persisted sync cursors advance only after the observed batch is processed.
