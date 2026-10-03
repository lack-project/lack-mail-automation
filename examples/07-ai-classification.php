<?php

declare(strict_types=1);

use Lack\MailAutomation\Analysis\AnalyzedMail;
use Lack\MailAutomation\Analysis\MailAnalyzer;
use Lack\MailAutomation\Attributes\OnMailAction;
use Lack\MailAutomation\MailAction;
use Lack\MailAutomation\MailAutomation;
use Lack\MailAutomation\MailContext;
use Phore\AiHarness\PromptType\PromptFile;
use Phore\MailClient\MailboxConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

#[OnMailAction(
    when: [MetaLeadAction::class, 'isForwarder'],
    condition: 'The current message contains only applicant lead data and this lead has not yet received the initial reply.',
)]
final class MetaLeadAction
{
    public static function isForwarder(AnalyzedMail $mail, MailContext $context): bool
    {
        return count($mail->from) === 1 && strtolower($mail->from[0]) === 'lead-forwarder@example.org';
    }

    public function __invoke(AnalyzedMail $mail, MailContext $context): MailAction
    {
        return $mail->ai_answer(
            new PromptFile(__DIR__ . '/prompts/07-initial-reply.md'),
        );
    }
}

#[OnMailAction(
    condition: 'The applicant explicitly asks to create or change the profile and this request is still open in the conversation.',
)]
final class ProfileAction
{
    public function __invoke(AnalyzedMail $mail, MailContext $context): MailAction
    {
        $scope = $mail->scopeFor('recipient:' . strtolower($mail->from[0]));
        $scope->putFile('profile.md', '# Profile', 'text/markdown');

        return $mail->ai_answer(
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
