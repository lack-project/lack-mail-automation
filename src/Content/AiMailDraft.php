<?php

declare(strict_types=1);

namespace Lack\MailAutomation\Content;

use DateTimeImmutable;
use InvalidArgumentException;
use Lack\MailAutomation\MailAction;
use Lack\MailAutomation\MailActions;
use Phore\AiHarness\Content\AiDocument;
use Phore\MailClient\Email;

/**
 * AI-generated outbound draft.
 *
 * AiMailDraft is an AiMail with delivery-only operations. Its fluent setters
 * return a new draft, keeping AI content identity and type safety explicit.
 */
final readonly class AiMailDraft extends AiMail
{
    public const MODE_MAIL = 'mail';
    public const MODE_REPLY = 'reply';
    public const MODE_FORWARD = 'forward';

    /**
     * @param string|array|null $to Recipient for new/forward mails.
     * @param list<string> $aliases Prompt aliases for the generated mail.
     * @param list<AiDocument> $draftAttachments Attachments queued with the draft.
     * @example $draft = $mail->ai_reply($prompt)->setSubject('Re: Profil');
     * @see AiMail::ai_reply()
     */
    public function __construct(
        public AiMail $source,
        string $markdown,
        public string $mode,
        public string|array|null $draftTo = null,
        public ?string $draftSubject = null,
        public bool $answerable = true,
        public string $reason = '',
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
        private array $draftAttachments = [],
    ) {
        if (!in_array($mode, [self::MODE_MAIL, self::MODE_REPLY, self::MODE_FORWARD], true)) {
            throw new InvalidArgumentException('Unknown AI mail draft mode: ' . $mode);
        }
        foreach ($draftAttachments as $attachment) {
            if (!$attachment instanceof AiDocument) {
                throw new InvalidArgumentException('AI mail draft attachments must be AiDocument objects.');
            }
        }

        $resolvedTo = $draftTo ?? ($mode === self::MODE_REPLY ? $source->from : []);
        $resolvedSubject = $draftSubject
            ?? ($mode === self::MODE_REPLY ? $source->subject : '');
        $generatedId = $id ?? 'draft:' . hash('sha256', json_encode([
            $source->getId(),
            $mode,
            $resolvedTo,
            $resolvedSubject,
            $markdown,
        ], JSON_THROW_ON_ERROR));

        $original = (new Email(to: $resolvedTo, subject: $resolvedSubject))
            ->withMarkdown($markdown);
        $metadata = new MailSummary(
            id: $generatedId,
            messageId: null,
            subject: $resolvedSubject,
            from: [],
            to: array_values((array) $resolvedTo),
            cc: [],
            date: null,
            observedAt: new DateTimeImmutable(),
            direction: 'outgoing',
            references: [],
            inReplyTo: $mode === self::MODE_REPLY ? $source->messageId : null,
            content: $markdown,
            summary: 'AI-generated outbound mail draft.',
            attachments: [],
            complete: $answerable,
        );

        parent::__construct(
            original: $original,
            metadata: $metadata,
            attachments: $draftAttachments,
            conversationAttachments: $source->conversationAttachments,
            history: $source->history,
            missingReferences: $source->missingReferences,
            conversation: $source->conversation,
            scopeStore: $source->scopeStore,
            aiOptions: $source->aiOptions,
            historyLoader: $source->historyLoader,
            context: $source->ai_get_context(),
            prepareConversation: false,
            id: $generatedId,
            aliases: array_values(array_unique(['draft', ...$aliases])),
            instructions: $instructions,
        );
    }

    public function recipient(): string|array|null
    {
        return $this->draftTo;
    }

    public function subject(): ?string
    {
        return $this->draftSubject;
    }

    /** @return list<AiDocument> */
    public function draftAttachments(): array
    {
        return $this->draftAttachments;
    }

    public function setRecipient(string|array $recipient): self
    {
        return $this->copy(to: $recipient);
    }

    public function setSubject(string $subject): self
    {
        return $this->copy(subject: $subject);
    }

    public function setText(string $markdown): self
    {
        return $this->copy(markdown: $markdown);
    }

    public function attach(AiDocument $attachment): self
    {
        return $this->copy(attachments: [...$this->draftAttachments, $attachment]);
    }

    /**
     * Schedule the draft for normal delivery.
     *
     * Delivery is executed by MailAutomation. The Sent folder remains the
     * source of truth for the resulting conversation history.
     */
    public function send(): MailAction
    {
        if (!$this->answerable || trim($this->getContent()) === '') {
            return MailActions::actionRequired();
        }

        return MailActions::schedule()->sendAiMailDraft($this);
    }

    /**
     * Save the draft without sending it.
     */
    public function draft(): MailAction
    {
        if (!$this->answerable || trim($this->getContent()) === '') {
            return MailActions::actionRequired();
        }

        return MailActions::schedule()->saveAiMailDraft($this);
    }

    public function asForward(): self
    {
        return $this->copy(mode: self::MODE_FORWARD);
    }

    private function copy(
        ?string $markdown = null,
        ?string $mode = null,
        string|array|null $to = null,
        ?string $subject = null,
        ?array $attachments = null,
    ): self {
        return new self(
            source: $this->source,
            markdown: $markdown ?? $this->getContent(),
            mode: $mode ?? $this->mode,
            draftTo: $to ?? $this->draftTo,
            draftSubject: $subject ?? $this->draftSubject,
            answerable: $this->answerable,
            reason: $this->reason,
            id: $this->getId(),
            aliases: $this->getAliases(),
            instructions: $this->getInstructions(),
            draftAttachments: $attachments ?? $this->draftAttachments,
        );
    }
}
