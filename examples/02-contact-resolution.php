<?php
use Lack\MailAutomation\Attributes\OnFolderAutomation;
use Lack\MailAutomation\ContactResolutionStatus;
use Lack\MailAutomation\Folder;
use Lack\MailAutomation\MailActions;
use Lack\MailAutomation\MailContext;
use Phore\MailClient\Email;

// Add after the MailAutomation setup from 01-overview.php.
#[OnFolderAutomation(folder: Folder::Inbox, priority: 200)]
function routeByContactResolution(Email $mail, MailContext $context): MailActions
{
    return match ($context->contactResolution->status) {
        ContactResolutionStatus::Unknown =>
            MailActions::create()->addFlag('new_contact')->moveTo('NewContacts'),
        ContactResolutionStatus::KnownAddress,
        ContactResolutionStatus::AliasAdded =>
            MailActions::create()->moveTo('Customers'),
        ContactResolutionStatus::ContactCreated =>
            MailActions::create()->addFlag('new_contact')->moveTo('NewContacts'),
        ContactResolutionStatus::Conflict,
        ContactResolutionStatus::OutgoingMissing =>
            MailActions::create()->addFlag('phore_review')->moveTo('Review'),
    };
}

$automation->addRules('routeByContactResolution');
$report = $automation->run();

// Verified first reply: ContactCreated; differing From is stored as an alias.
// Known alias: KnownAddress. Verified new alias: AliasAdded.
// Contradictory evidence or missing live Sent evidence: Review, with no merge.
