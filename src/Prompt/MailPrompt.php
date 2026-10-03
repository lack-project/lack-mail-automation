<?php

declare(strict_types=1);

namespace Lack\MailAutomation\Prompt;

use Lack\MailAutomation\Analysis\AnalyzedMail;
use Lack\MailAutomation\Analysis\MailSummary;
use Phore\AiHarness\PromptType\StructPrompt;

/**
 * Creates schema-backed structured prompts for one analyzed mail.
 *
 * The returned StructPrompt is a native phore/ai-harness prompt and can be
 * passed directly to phore_ai_*() helpers. Mail content is untrusted by default.
 */
final class MailPrompt
{
    /**
     * @param AnalyzedMail|MailSummary $mail Current or historical analyzed mail.
     * @param ?string $alias Reference alias used by PromptFile frontmatter.
     * @param ?string $instructions Optional application instructions for this source.
     * @param bool $allowInstructions Whether instructions contained inside mail data may influence the model.
     * @return StructPrompt Native AI Harness prompt with generated JSON schema and data.
     * @example $prompt = MailPrompt::from($mail, alias: 'incomingEmail');
     * @see StructPrompt
     */
    public static function from(
        AnalyzedMail|MailSummary $mail,
        ?string $alias = 'mail',
        ?string $instructions = null,
        bool $allowInstructions = false,
    ): StructPrompt {
        $summary = $mail instanceof AnalyzedMail ? $mail->metadata : $mail;

        return new StructPrompt(
            MailPromptData::fromSummary($summary),
            alias: $alias,
            instructions: $instructions,
            allowInstructions: $allowInstructions,
        );
    }
}

/**
 * Creates schema-backed structured prompts for the complete analyzed conversation.
 */
final class ConversationPrompt
{
    /**
     * @param AnalyzedMail $mail Target mail whose conversation should be supplied.
     * @param ?string $alias Reference alias used by PromptFile frontmatter.
     * @param ?string $instructions Optional application instructions for this source.
     * @param bool $allowInstructions Whether instructions contained inside conversation data may influence the model.
     * @return StructPrompt Native AI Harness prompt with generated JSON schema and data.
     * @example $prompt = ConversationPrompt::from($mail, alias: 'conversation');
     * @see MailPrompt
     */
    public static function from(
        AnalyzedMail $mail,
        ?string $alias = 'conversation',
        ?string $instructions = null,
        bool $allowInstructions = false,
    ): StructPrompt {
        $messages = array_map(
            static fn (MailSummary $item): MailPromptData => MailPromptData::fromSummary($item),
            [...$mail->getHistory(), $mail->metadata],
        );
        usort(
            $messages,
            static fn (MailPromptData $a, MailPromptData $b): int =>
                strcmp($a->date ?? $a->observedAt, $b->date ?? $b->observedAt)
                ?: strcmp($a->id, $b->id),
        );

        return new StructPrompt(
            new ConversationPromptData(
                targetMessageId: $mail->metadata->id,
                missingReferences: $mail->missingReferences,
                messages: $messages,
                scope: $mail->scope()->snapshot(),
            ),
            alias: $alias,
            instructions: $instructions,
            allowInstructions: $allowInstructions,
        );
    }
}

final readonly class MailPromptData
{
    /**
     * @param list<string> $from
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $references
     * @param list<array<string,mixed>> $attachments
     */
    public function __construct(
        public string $id,
        public ?string $messageId,
        public string $subject,
        public array $from,
        public array $to,
        public array $cc,
        public ?string $date,
        public string $observedAt,
        public string $direction,
        public array $references,
        public ?string $inReplyTo,
        public string $content,
        public string $summary,
        public array $attachments,
        public bool $complete,
    ) {
    }

    public static function fromSummary(MailSummary $mail): self
    {
        return new self(
            id: $mail->id,
            messageId: $mail->messageId,
            subject: $mail->subject,
            from: $mail->from,
            to: $mail->to,
            cc: $mail->cc,
            date: $mail->date?->format(DATE_ATOM),
            observedAt: $mail->observedAt->format(DATE_ATOM),
            direction: $mail->direction,
            references: $mail->references,
            inReplyTo: $mail->inReplyTo,
            content: $mail->content,
            summary: $mail->summary,
            attachments: $mail->attachments,
            complete: $mail->complete,
        );
    }
}

final readonly class ConversationPromptData
{
    /**
     * @param list<string> $missingReferences
     * @param list<MailPromptData> $messages
     * @param array<string,mixed> $scope
     */
    public function __construct(
        public string $targetMessageId,
        public array $missingReferences,
        public array $messages,
        public array $scope,
    ) {
    }
}
