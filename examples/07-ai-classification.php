<?php

declare(strict_types=1);

use Lack\MailAutomation\Analysis\AnalyzedMail;
use Lack\MailAutomation\Analysis\MailAnalyzer;
use Lack\MailAutomation\Attributes\OnMailAction;
use Lack\MailAutomation\MailAction;
use Lack\MailAutomation\MailAutomation;
use Lack\MailAutomation\MailContext;
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
        $scope = $mail->scope();
        $scope->set('lastAction', 'metaLead');

        return $mail->reply('Thanks, we received your application data.');
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
        $previous = $scope->getFile('profile.md');
        $profile = $previous?->content ?? '# New profile';

        $scope->putFile('profile.md', $profile, 'text/markdown');

        return $mail->reply('Your profile draft is ready.');
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
