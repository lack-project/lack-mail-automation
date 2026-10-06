<?php

declare(strict_types=1);

use Lack\MailAutomation\Analysis\MailAnalyzer;
use Lack\MailAutomation\Attributes\OnMailAction;
use Lack\MailAutomation\Content\MailContent;
use Lack\MailAutomation\MailAction;
use Lack\MailAutomation\MailAutomation;
use Phore\AiHarness\PromptType\PromptFile;
use Phore\MailClient\MailboxConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

#[OnMailAction(
    condition: 'The current mail contains a new applicant lead and no initial contact for this lead appears later in the conversation.',
)]
final class InitialContact
{
    public function __invoke(MailContent $mail): MailAction
    {
        return $mail->ai_mail([
            new PromptFile(__DIR__ . '/prompts/07-initial-reply.md'),
            'Derive recipient address and salutation from the lead mail.',
        ]);
    }
}

#[OnMailAction(
    condition: 'This is the applicant first reply to our initial contact and more CV information is still required.',
)]
final class RequestDetails
{
    public function __invoke(MailContent $mail): MailAction
    {
        return $mail->ai_reply(
            new PromptFile(__DIR__ . '/prompts/07-profile-reply.md'),
        );
    }
}

$client = MailboxConfig::fromFile($argv[1])->connect();
$automation = new MailAutomation(
    client: $client,
    storage: $argv[2],
    mailAnalyzer: new MailAnalyzer(),
);

$automation->addMailActions([
    new InitialContact(),
    new RequestDetails(),
]);

$automation->run();
