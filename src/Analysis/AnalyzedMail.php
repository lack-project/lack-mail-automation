<?php

declare(strict_types=1);

namespace Lack\MailAutomation\Analysis;

use DateTimeImmutable;
use Lack\MailAutomation\MailAction;
use Lack\MailAutomation\MailActions;
use Lack\MailAutomation\Prompt\ConversationPrompt;
use Lack\MailAutomation\Prompt\MailPrompt;
use Phore\AiHarness\AiContextTrait;
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

final readonly class GeneratedMailDraft
{
    public function __construct(
        public bool $answerable,
        public string $subject,
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
    use AiContextTrait;

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
     * StructPrompt can be passed directly to phore_ai_* helpers without manually
     * copying subject, addresses, body or attachment summaries.
     *
     * @param ?string $alias Prompt reference alias.
     * @param ?string $instructions Optional application guidance for this source.
     * @param bool $allowInstructions Whether instructions contained in the mail itself may influence the model.
     * @return StructPrompt Schema-backed prompt containing the current mail.
     * @example $draft = $mail->ai_struct([$promptFile, $mail->prompt('incomingEmail')], Draft::class);
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
     * Generate and schedule a reply using this mail's bound AI conversation.
     *
     * MailAnalyzer prepares the AI context with conversationPrompt(), so callers
     * normally pass only the application PromptFile. The same context cursor is
     * reused by action matching and subsequent ai_* calls on this object.
     *
     * @param string|PromptType|array<int,string|PromptType> $prompt Application prompt or prompt list.
     * @param array<string,mixed> $options Per-call phore/ai-harness options.
     * @param null|callable(ResponseMailDraft, self): array<Attachment|AnalyzedAttachment> $attachments Attachment factory.
     * @return MailAction Scheduled reply or actionRequired when the model cannot answer safely.
     * @throws UnexpectedValueException If the attachment callback does not return an array.
     * @example return $mail->ai_answer(new PromptFile(__DIR__ . '/reply.md'));
     * @see AiContextTrait::ai_struct()
     */
    public function ai_answer(
        string|PromptType|array $prompt,
        array $options = [],
        ?callable $attachments = null,
    ): MailAction {
        $prompts = is_array($prompt) ? array_values($prompt) : [$prompt];
        $prompts[] = 'Return answerable=false when the supplied conversation is insufficient or contradictory. '
            . 'When answerable=true, markdown must contain only the complete send-ready reply body. '
            . 'Use reason for a short explanation when answerable=false.';

        /** @var ResponseMailDraft $draft */
        $draft = $this->ai_struct($prompts, ResponseMailDraft::class, $options);
        if (!$draft->answerable || trim($draft->markdown) === '') {
            return MailActions::actionRequired();
        }

        return $this->reply($draft->markdown, $this->resolveGeneratedAttachments($attachments, $draft));
    }

    /**
     * Alias for ai_answer() when application vocabulary prefers reply.
     *
     * @param string|PromptType|array<int,string|PromptType> $prompt Application prompt or prompt list.
     * @param array<string,mixed> $options Per-call phore/ai-harness options.
     * @param null|callable(ResponseMailDraft, self): array<Attachment|AnalyzedAttachment> $attachments Attachment factory.
     * @return MailAction Scheduled reply or actionRequired.
     * @example return $mail->ai_reply(new PromptFile(__DIR__ . '/reply.md'));
     * @see ai_answer()
     */
    public function ai_reply(
        string|PromptType|array $prompt,
        array $options = [],
        ?callable $attachments = null,
    ): MailAction {
        return $this->ai_answer($prompt, $options, $attachments);
    }

    /**
     * Generate and schedule a new mail to an explicitly supplied recipient.
     *
     * The model generates subject/body only; recipients remain deterministic
     * application input and are never invented by the model.
     *
     * @param string|array $to Recipient accepted by phore/mail-client Email.
     * @param string|PromptType|array<int,string|PromptType> $prompt Application prompt or prompt list.
     * @param array<string,mixed> $options Per-call phore/ai-harness options.
     * @param null|callable(GeneratedMailDraft, self): array<Attachment|AnalyzedAttachment> $attachments Attachment factory.
     * @param ?string $subject Fixed subject; null lets the model generate it.
     * @return MailAction Scheduled new mail or actionRequired.
     * @example return $mail->ai_mail($recipient, new PromptFile(__DIR__ . '/initial.md'));
     * @see MailActions::schedule()
     */
    public function ai_mail(
        string|array $to,
        string|PromptType|array $prompt,
        array $options = [],
        ?callable $attachments = null,
        ?string $subject = null,
    ): MailAction {
        $prompts = is_array($prompt) ? array_values($prompt) : [$prompt];
        $prompts[] = $subject === null
            ? 'Return answerable=false when the supplied context is insufficient or contradictory. '
                . 'When answerable=true, return a concise subject and the complete send-ready markdown body.'
            : 'Return answerable=false when the supplied context is insufficient or contradictory. '
                . 'When answerable=true, return the complete send-ready markdown body. The application fixes the subject.';

        /** @var GeneratedMailDraft $draft */
        $draft = $this->ai_struct($prompts, GeneratedMailDraft::class, $options);
        if (!$draft->answerable || trim($draft->markdown) === '') {
            return MailActions::actionRequired();
        }

        $resolvedSubject = $subject ?? trim($draft->subject);
        if ($resolvedSubject === '') {
            return MailActions::actionRequired();
        }

        $mail = (new Email(to: $to, subject: $resolvedSubject))->withMarkdown($draft->markdown);
        foreach ($this->normalizeAttachments($this->resolveGeneratedAttachments($attachments, $draft)) as $attachment) {
            $mail = $mail->attach($attachment);
        }

        return MailActions::schedule()->sendMail($mail);
    }

    /**
     * Generate and schedule a forward-style mail with a deterministic recipient.
     *
     * The subject defaults to the source subject prefixed with Fwd:. Original
     * attachments can be copied explicitly; the generated body is controlled by
     * the supplied PromptFile and the prepared conversation context.
     *
     * @param string|array $to Forward recipient.
     * @param string|PromptType|array<int,string|PromptType> $prompt Application prompt or prompt list.
     * @param array<string,mixed> $options Per-call phore/ai-harness options.
     * @param bool $includeOriginalAttachments Copy the current source attachments.
     * @return MailAction Scheduled forward-style mail or actionRequired.
     * @example return $mail->ai_forward('office@example.org', new PromptFile(__DIR__ . '/forward.md'));
     * @see ai_mail()
     */
    public function ai_forward(
        string|array $to,
        string|PromptType|array $prompt,
        array $options = [],
        bool $includeOriginalAttachments = false,
    ): MailAction {
        $subject = preg_match('/^Fwd:/i', $this->subject) === 1
            ? $this->subject
            : 'Fwd: ' . $this->subject;

        $attachments = $includeOriginalAttachments
            ? fn (): array => $this->attachments
            : null;

        return $this->ai_mail($to, $prompt, $options, $attachments, $subject);
    }

    /**
     * Backwards-compatible name for ai_answer().
     *
     * @param string|PromptType|array<int,string|PromptType> $prompt Application prompt or prompt list.
     * @param array<string,mixed> $options Per-call phore/ai-harness options.
     * @param null|callable(ResponseMailDraft, self): array<Attachment|AnalyzedAttachment> $attachments Attachment factory.
     * @return MailAction Scheduled reply or actionRequired.
     * @example return $mail->createResponseMail(new PromptFile(__DIR__ . '/reply.md'));
     * @see ai_answer()
     */
    public function createResponseMail(
        string|PromptType|array $prompt,
        array $options = [],
        ?callable $attachments = null,
    ): MailAction {
        return $this->ai_answer($prompt, $options, $attachments);
    }

    /**
     * Return legacy array context for compatibility.
     *
     * Prefer the bound ai_* API for AI requests so the prepared conversation and
     * shared provider cursor are not reconstructed in consumers.
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
        return MailActions::schedule()->sendReply($markdown, $this->normalizeAttachments($attachments));
    }

    /**
     * @param null|callable(object, self): array<Attachment|AnalyzedAttachment> $callback
     * @return array<Attachment|AnalyzedAttachment>
     */
    private function resolveGeneratedAttachments(?callable $callback, object $draft): array
    {
        if ($callback === null) {
            return [];
        }

        $files = $callback($draft, $this);
        if (!is_array($files)) {
            throw new UnexpectedValueException('Generated mail attachment callback must return an array.');
        }

        return array_values($files);
    }

    /** @return list<Attachment> */
    private function normalizeAttachments(array $attachments): array
    {
        $files = [];
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof Attachment && !$attachment instanceof AnalyzedAttachment) {
                throw new \InvalidArgumentException('Mail attachments must be Attachment or AnalyzedAttachment objects.');
            }
            $files[] = $attachment instanceof AnalyzedAttachment ? $attachment->toAttachment() : $attachment;
        }

        return $files;
    }
}
