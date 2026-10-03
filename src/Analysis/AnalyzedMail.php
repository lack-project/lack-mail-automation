<?php

declare(strict_types=1);

namespace Lack\MailAutomation\Analysis;

use DateTimeImmutable;
use Lack\MailAutomation\MailAction;
use Lack\MailAutomation\MailActions;
use Lack\MailAutomation\Prompt\ConversationPrompt;
use Lack\MailAutomation\Prompt\MailPrompt;
use Phore\AiHarness\PromptType\PromptType;
use Phore\AiHarness\PromptType\StructPrompt;
use Phore\FileSystem\PhoreTempFile;
use Phore\MailClient\Attachment;
use Phore\MailClient\Email;
use UnexpectedValueException;

final readonly class ContentAnalysis
{
    public function __construct(
        public string $summary,
        public string $content,
        public bool $complete = true,
        public ?string $issue = null,
    ) {
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            summary: $data['summary'],
            content: $data['content'],
            complete: $data['complete'],
            issue: $data['issue'],
        );
    }
}

final readonly class ExtractedContent
{
    public function __construct(
        public string $content,
        public string $summary,
        public bool $complete,
        public ?string $issue,
    ) {
    }
}

final readonly class ResponseMailDraft
{
    public function __construct(
        public bool $answerable,
        public string $markdown,
        public string $reason,
    ) {
    }
}

final readonly class AnalyzedAttachment
{
    public int $size;

    public function __construct(
        public string $id,
        public string $filename,
        public string $mediaType,
        public ContentAnalysis $analysis,
        private string $rawContent,
        public ?string $contentId = null,
    ) {
        $this->size = strlen($rawContent);
    }

    public function getContent(): string
    {
        return $this->analysis->content;
    }

    public function getSummary(): string
    {
        return $this->analysis->summary;
    }

    public function getRawContent(): string
    {
        return $this->rawContent;
    }

    public function getRawFile(): PhoreTempFile
    {
        $file = phore_tempfile();
        $file->set_contents($this->rawContent);

        return $file;
    }

    public function toAttachment(): Attachment
    {
        return Attachment::fromBytes($this->filename, $this->mediaType, $this->rawContent, $this->contentId);
    }

    public function summaryData(): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'mediaType' => $this->mediaType,
            'summary' => $this->analysis->summary,
            'complete' => $this->analysis->complete,
            'issue' => $this->analysis->issue,
        ];
    }
}

final readonly class MailSummary
{
    public function __construct(
        public string $id,
        public ?string $messageId,
        public string $subject,
        public array $from,
        public array $to,
        public array $cc,
        public ?DateTimeImmutable $date,
        public DateTimeImmutable $observedAt,
        public string $direction,
        public array $references,
        public ?string $inReplyTo,
        public string $content,
        public string $summary,
        public array $attachments,
        public bool $complete,
        public ?string $serverId = null,
    ) {
    }

    public function toArray(): array
    {
        $data = get_object_vars($this);
        $data['date'] = $this->date?->format('Y-m-d\TH:i:s.uP');
        $data['observedAt'] = $this->observedAt->format('Y-m-d\TH:i:s.uP');

        return $data;
    }

    public static function fromArray(array $data): self
    {
        $data['date'] = $data['date'] === null ? null : new DateTimeImmutable($data['date']);
        $data['observedAt'] = new DateTimeImmutable($data['observedAt']);
        $data['content'] ??= '';
        unset($data['classification']);

        return new self(...$data);
    }
}

final readonly class AnalyzedMail
{
    public string $subject;
    public ?DateTimeImmutable $date;
    public array $from;
    public array $to;
    public array $cc;
    public ?string $messageId;

    public function __construct(
        private Email $original,
        public ContentAnalysis $analysis,
        public MailSummary $metadata,
        private array $attachments,
        private array $history,
        public array $missingReferences,
        private ConversationScope $conversation,
        private ConversationStore $scopeStore,
        private ?\Closure $historyLoader = null,
    ) {
        $this->subject = $metadata->subject;
        $this->date = $metadata->date;
        $this->from = $metadata->from;
        $this->to = $metadata->to;
        $this->cc = $metadata->cc;
        $this->messageId = $metadata->messageId;
    }

    public function getContent(): string
    {
        return $this->original->body()->text();
    }

    public function getRawContent(): string
    {
        return $this->original->body()->html() ?? $this->original->body()->text();
    }

    public function getOriginalMail(): Email
    {
        return $this->original;
    }

    public function getSummary(): string
    {
        return $this->analysis->summary;
    }

    /** @return list<AnalyzedAttachment> */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function getAttachment(string $id): AnalyzedAttachment
    {
        foreach ($this->attachments as $attachment) {
            if ($attachment->id === $id) {
                return $attachment;
            }
        }

        throw new \OutOfBoundsException('Unknown attachment ' . $id . ' in mail ' . $this->metadata->id);
    }

    /** @return list<MailSummary> */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function loadPrevious(string $id): self
    {
        foreach ($this->history as $entry) {
            if ($entry->id === $id) {
                if ($this->historyLoader === null) {
                    throw new \LogicException('No historical message loader is available.');
                }

                return ($this->historyLoader)($entry);
            }
        }

        throw new \OutOfBoundsException('Message is not part of this conversation: ' . $id);
    }

    public function scope(): ConversationScope
    {
        return $this->conversation;
    }

    public function scopeFor(string $id): ConversationScope
    {
        return new ConversationScope($id, $this->scopeStore);
    }

    /**
     * Create a native schema-backed AI Harness prompt for this mail.
     *
     * Mail content remains external/untrusted data by default. The returned
     * StructPrompt can be passed directly to phore_ai_text(), phore_ai_struct()
     * and the other phore/ai-harness helpers without manually copying fields.
     *
     * @param ?string $alias Prompt reference alias.
     * @param ?string $instructions Optional application guidance for this source.
     * @param bool $allowInstructions Whether instructions contained in the mail itself may influence the model.
     * @return StructPrompt Schema-backed prompt containing the current mail.
     * @example $draft = phore_ai_struct([$promptFile, $mail->prompt('incomingEmail')], Draft::class);
     * @see MailPrompt::from()
     */
    public function prompt(
        ?string $alias = 'mail',
        ?string $instructions = null,
        bool $allowInstructions = false,
    ): StructPrompt {
        return MailPrompt::from($this, $alias, $instructions, $allowInstructions);
    }

    /**
     * Create a native schema-backed prompt for the full observed conversation.
     *
     * The prompt contains chronological full mail bodies, attachment summaries,
     * missing-reference information and the conversation-scope inventory.
     *
     * @param ?string $alias Prompt reference alias.
     * @param ?string $instructions Optional application guidance for this source.
     * @param bool $allowInstructions Whether instructions embedded in mail data may influence the model.
     * @return StructPrompt Schema-backed prompt for the complete conversation.
     * @example $answer = phore_ai_text([$promptFile, $mail->conversationPrompt()]);
     * @see ConversationPrompt::from()
     */
    public function conversationPrompt(
        ?string $alias = 'conversation',
        ?string $instructions = null,
        bool $allowInstructions = false,
    ): StructPrompt {
        return ConversationPrompt::from($this, $alias, $instructions, $allowInstructions);
    }

    /**
     * Generate and schedule a reply directly from an application prompt.
     *
     * The full conversation prompt is appended automatically. The structured
     * result can explicitly decline when the supplied context is insufficient.
     * Long reply instructions should normally live in a PromptFile. The optional
     * attachment callback runs only after a usable draft was generated.
     *
     * @param string|PromptType|array<int,string|PromptType> $prompt Application prompt or prompt list.
     * @param array<string,mixed> $options phore/ai-harness request options.
     * @param null|callable(ResponseMailDraft, self): array<Attachment|AnalyzedAttachment> $attachments Attachment factory.
     * @return MailAction Scheduled reply or actionRequired when the model cannot answer safely.
     * @throws UnexpectedValueException If the attachment callback does not return an array.
     * @example return $mail->createResponseMail(new PromptFile(__DIR__ . '/reply.md'), $aiOptions);
     * @see conversationPrompt()
     */
    public function createResponseMail(
        string|PromptType|array $prompt,
        array $options = [],
        ?callable $attachments = null,
    ): MailAction {
        $prompts = is_array($prompt) ? array_values($prompt) : [$prompt];
        $prompts[] = $this->conversationPrompt();
        $prompts[] = 'Return answerable=false when the supplied conversation is insufficient or contradictory. '
            . 'When answerable=true, markdown must contain only the complete send-ready reply body. '
            . 'Use reason for a short explanation when answerable=false.';

        /** @var ResponseMailDraft $draft */
        $draft = phore_ai_struct($prompts, ResponseMailDraft::class, $options);

        if (!$draft->answerable || trim($draft->markdown) === '') {
            return MailActions::actionRequired();
        }

        $files = $attachments === null ? [] : $attachments($draft, $this);
        if (!is_array($files)) {
            throw new UnexpectedValueException('Response mail attachment callback must return an array.');
        }

        return $this->reply($draft->markdown, $files);
    }

    /**
     * Return legacy array context for compatibility.
     *
     * Prefer conversationPrompt() for AI requests so schema and source policy are
     * applied centrally instead of reconstructing StructPrompt values in consumers.
     */
    public function routingContext(): array
    {
        $messages = [...$this->history, $this->metadata];
        usort(
            $messages,
            static fn (MailSummary $a, MailSummary $b): int =>
                ($a->date ?? $a->observedAt) <=> ($b->date ?? $b->observedAt) ?: strcmp($a->id, $b->id),
        );

        return [
            'targetMessageId' => $this->metadata->id,
            'missingReferences' => $this->missingReferences,
            'messages' => array_map(static fn (MailSummary $entry): array => $entry->toArray(), $messages),
            'scope' => $this->conversation->snapshot(),
        ];
    }

    public function reply(string $markdown, array $attachments = []): MailAction
    {
        $files = [];
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof Attachment && !$attachment instanceof AnalyzedAttachment) {
                throw new \InvalidArgumentException('Reply attachments must be Attachment or AnalyzedAttachment objects.');
            }
            $files[] = $attachment instanceof AnalyzedAttachment ? $attachment->toAttachment() : $attachment;
        }

        return MailActions::schedule()->sendReply($markdown, $files);
    }
}
