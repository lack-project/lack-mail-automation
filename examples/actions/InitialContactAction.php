<?php

declare(strict_types=1);

use Lack\MailAutomation\Attributes\OnMailAction;
use Lack\MailAutomation\Content\AiMail;
use Lack\MailAutomation\MailAction;
use Phore\AiHarness\PromptType\PromptFile;

#[OnMailAction(
    condition: 'The current mail contains one new applicant lead and no initial contact was sent for it yet.',
    priority: 100,
)]
final class InitialContactAction
{
    public function __invoke(AiMail $mail): MailAction
    {
        return $mail->ai_mail(
            new PromptFile(__DIR__ . '/../prompts/01-initial-contact.md'),
        )->setSubject('Your application profile')->send();
    }
}
