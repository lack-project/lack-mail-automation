<?php

declare(strict_types=1);

use Lack\MailAutomation\Analysis\MailAnalyzer;
use Lack\MailAutomation\Attributes\OnMailAction;
use Lack\MailAutomation\Content\MailContent;
use Lack\MailAutomation\MailAction;
use Lack\MailAutomation\MailAutomation;
use Lack\MailAutomation\MailContext;
use Phore\AiHarness\PromptType\PromptFile;
use Phore\MailClient\MailboxConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

#[OnMailAction(
    condition: 'The current mail is a new social-media lead containing applicant contact data, is not a security code or an ordinary applicant reply, and no initial contact has already been created later in the conversation.',
)]
final class MetaLeadAction
{
    public function __invoke(
        MailContent $mail,
        MailContext $context,
    ): MailAction {
        return $mail->ai_mail(
            null,
            new PromptFile(__DIR__ . '/prompts/07-initial-reply.md'),
        );
    }
}

#[OnMailAction(
    condition: 'The applicant asks to create or change the profile and this request is still open.',
)]
final class ProfileAction
{
    public function __invoke(
        MailContent $mail,
        MailContext $context,
    ): MailAction {
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
    new MetaLeadAction(),
    new ProfileAction(),
]);

$automation->run();
