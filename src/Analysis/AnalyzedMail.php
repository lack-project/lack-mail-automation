<?php

declare(strict_types=1);

namespace Lack\MailAutomation\Analysis;

use DateTimeImmutable;
use Lack\MailAutomation\MailAction;
use Lack\MailAutomation\MailActions;
use Phore\FileSystem\PhoreTempFile;
use Phore\MailClient\Attachment;
use Phore\MailClient\Email;

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
     * Return the complete analyzed mail conversation plus scope state.
     *
     * Mail bodies are complete decoded text. Attachments contribute summaries;
     * full attachment text/bytes remain available to the selected action.
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
