<?php
use Lack\MailAutomation\Attributes\OnFolderAutomation;
use Lack\MailAutomation\ContactResolutionStatus;
use Lack\MailAutomation\Folder;
use Lack\MailAutomation\MailAction;
use Lack\MailAutomation\MailActions;
use Lack\MailAutomation\MailContext;
use Phore\MailClient\Email;

// Add after the MailAutomation setup from 01-overview.php.
#[OnFolderAutomation(folder: Folder::Inbox, priority: 200)]
function routeByContactResolution(Email $mail, MailContext $context): MailAction
{
    return match ($context->contactResolution->status) {
        ContactResolutionStatus::Unknown =>
            MailActions::schedule()->addFlag('new_contact')->moveTo('new_contacts'),
        ContactResolutionStatus::KnownAddress,
        ContactResolutionStatus::AliasAdded =>
            MailActions::moveTo('customers'),
        ContactResolutionStatus::ContactCreated =>
            MailActions::schedule()->addFlag('new_contact')->moveTo('new_contacts'),
        ContactResolutionStatus::Conflict,
        ContactResolutionStatus::OutgoingMissing =>
            MailActions::schedule()->addFlag('phore_review')->moveTo('review'),
    };
}

$automation->addRules('routeByContactResolution');
$report = $automation->run();

// Verified first reply: ContactCreated; differing From is stored as an alias.
// Known alias: KnownAddress. Verified new alias: AliasAdded.
// Contradictory evidence or missing live Sent evidence: review, with no merge.
