<?php
use Lack\MailAutomation\Attributes\OnFolderAutomation;
use Lack\MailAutomation\Folder;
use Lack\MailAutomation\MailActions;
use Lack\MailAutomation\MailContext;
use Lack\MailAutomation\MetadataBag;
use Phore\MailClient\Email;

final class CustomerMetadata
{
    public function __construct(private MetadataBag $metadata) {}
    public function isB2b(): bool { return $this->metadata->get('classification') === 'b2b'; }
    public function markReviewed(): void { $this->metadata->set('reviewed', true); }
}

final class CustomerRules
{
    #[OnFolderAutomation(folder: Folder::Inbox, priority: 150)]
    public function route(Email $mail, MailContext $context): MailActions
    {
        if ($context->contact === null) {
            return MailActions::pass();
        }

        $customer = $context->contact->metadata->typed(CustomerMetadata::class);
        $customer->markReviewed();

        return $customer->isB2b()
            ? MailActions::create()->moveTo('B2B')
            : MailActions::create()->moveTo('Customers');
    }
}

$automation->addRules(new CustomerRules());
$report = $automation->run();

// Contact metadata persists immediately. It affects routing only because this rule reads it.
