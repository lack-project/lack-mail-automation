<?php

declare(strict_types=1);

use Lack\MailAutomation\Attributes\OnMailAction;
use Lack\MailAutomation\Content\AiMail;
use Lack\MailAutomation\MailAction;
use Phore\AiHarness\PromptType\PromptFile;

#[OnMailAction(
    condition: 'This is the first applicant reply and important CV information is still missing.',
    priority: 50,
)]
final class ReplyAction
{
    public function __invoke(AiMail $mail): MailAction
    {
        return $mail->ai_reply(
            new PromptFile(__DIR__ . '/../prompts/01-reply.md'),
        )->send();
    }
}
