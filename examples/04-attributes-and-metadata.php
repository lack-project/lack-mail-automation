<?php
use Lack\MailAutomation\Attributes\OnFolderAutomation;
use Lack\MailAutomation\Folder;
use Lack\MailAutomation\MailAction;
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
    public function route(Email $mail, MailContext $context): MailAction
    {
        if ($context->contact === null) {
            $context->logger->debug('Pass message without resolved contact');
            return MailActions::pass();
        }

        $customer = $context->contact->metadata->typed(CustomerMetadata::class);
        $customer->markReviewed();

        $target = $customer->isB2b() ? 'b2b' : 'customers';
        $context->logger->scope('customer')->info('Route contact {} to {}', [$context->contact->id, $target]);

        return MailActions::moveTo($target);
    }
}

$automation->addRules(new CustomerRules());
$report = $automation->run();

// Contact metadata persists immediately. It affects routing only because this rule reads it.
// Handler diagnostics use MailContext::$logger, so they inherit the current message/automation context.
