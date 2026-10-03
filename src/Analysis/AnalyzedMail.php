<?php

declare(strict_types=1);

namespace Lack\MailAutomation\Analysis;

use BackedEnum;
use DateTimeImmutable;
use Lack\MailAutomation\MailAction;
use Lack\MailAutomation\MailActions;
use Phore\FileSystem\PhoreTempFile;
use Phore\MailClient\Attachment;
use Phore\MailClient\Email;

/** Immutable, persisted interpretation; original content is kept separately. */
final readonly class ContentAnalysis
{
    /**
     * Construct a result without performing I/O.
     * @param string $content Complete extracted text, never the short summary.
     * @param ?string $classification One configured classification ID, or null.
     * @param bool $complete False for unsupported, unreadable or partial input.
     * @example new ContentAnalysis('A CV.', 'Full CV text', 'cv');
     * @see MailAnalyzer
     */
    public function __construct(
        public string $summary,
        public string $content,
        public ?string $classification = null,
        public bool $complete = true,
        public ?string $issue = null,
    ) {}

    /** @internal Persistence representation, not a routing prompt. */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @internal Restore only application-owned analysis records. */
    public static function fromArray(array $data): self
    {
        return new self($data['summary'], $data['content'], $data['classification'], $data['complete'], $data['issue']);
    }
}

/** Structured-output contract for one PDF/image extraction. */
final readonly class ExtractedContent
{
    /** @internal Hydrated by phore/ai-harness. */
    public function __construct(
        public string $content,
        public string $summary,
        public bool $complete,
        public ?string $issue,
    ) {}
}

/** An attachment snapshot. Equal filenames do not imply equal attachment IDs. */
final readonly class AnalyzedAttachment
{
    public int $size;

    /**
     * Retain original bytes and their interpretation, without file-system I/O.
     * @param string $id Stable identifier within this message (attachment-1, ...).
     * @param string $rawContent Decoded binary attachment bytes, not base64.
     * @example new AnalyzedAttachment('attachment-1', 'cv.txt', 'text/plain', $analysis, 'Full CV text');
     * @see AnalyzedMail::getAttachments()
     */
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

    /**
     * Return extracted text, not binary PDF/image data; may be partial when complete=false.
     * @example $text = $attachment->getContent();
     * @see ContentAnalysis::$complete
     */
    public function getContent(): string
    {
        return $this->analysis->content;
    }

    /**
     * Return the stored short description without another AI request.
     * @example $description = $attachment->getSummary();
     * @see ContentAnalysis
     */
    public function getSummary(): string
    {
        return $this->analysis->summary;
    }

    /**
     * Return the original decoded bytes without I/O or conversion.
     * @example $bytes = $attachment->getRawContent();
     * @see self::getRawFile()
     */
    public function getRawContent(): string
    {
        return $this->rawContent;
    }

    /**
     * Materialize an independent temporary copy through phore/filesystem.
     * Keep the returned object alive while its path is used; it removes its file
     * on destruction. The untrusted original filename is never used as a path.
     * File-system exceptions propagate with path context.
     * @example $file = $attachment->getRawFile(); $bytes = $file->get_contents();
     * @see PhoreTempFile
     */
    public function getRawFile(): PhoreTempFile
    {
        $file = phore_tempfile();
        $file->set_contents($this->rawContent);
        return $file;
    }

    /**
     * Create an immutable mail-client attachment for a reply; nothing is sent.
     * @example $replyAttachment = $attachment->toAttachment();
     * @see Attachment::fromBytes()
     */
    public function toAttachment(): Attachment
    {
        return Attachment::fromBytes($this->filename, $this->mediaType, $this->rawContent, $this->contentId);
    }

    /** @internal Compact view deliberately excludes original bytes and full text. */
    public function summaryData(): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'mediaType' => $this->mediaType,
            'summary' => $this->analysis->summary,
            'classification' => $this->analysis->classification,
            'complete' => $this->analysis->complete,
            'issue' => $this->analysis->issue,
        ];
    }
}

/** Immutable historical message with compact attachment descriptions. */
final readonly class MailSummary
{
    /**
     * Construct a compact chronological entry; no raw attachments are retained.
     * @param list<string> $from
     * @param list<string> $to
     * @param list<string> $cc
     * @param list<string> $references
     * @param list<array<string,mixed>> $attachments
     * @example $entries = $mail->getHistory(); // MailSummary[]
     * @see AnalyzedMail::getHistory()
     */
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
        public string $summary,
        public ?string $classification,
        public array $attachments,
        public bool $complete,
        public ?string $serverId = null,
    ) {}

    /** @internal Safe, compact representation for persistence and routing. */
    public function toArray(): array
    {
        $data = get_object_vars($this);
        $data['date'] = $this->date?->format('Y-m-d\TH:i:s.uP');
        $data['observedAt'] = $this->observedAt->format('Y-m-d\TH:i:s.uP');
        return $data;
    }

    /** @internal Restore a versioned application-owned summary record. */
    public static function fromArray(array $data): self
    {
        $data['date'] = $data['date'] === null ? null : new DateTimeImmutable($data['date']);
        $data['observedAt'] = new DateTimeImmutable($data['observedAt']);
        return new self(...$data);
    }
}

/** The immutable value passed to an AI-selected application action. */
final readonly class AnalyzedMail
{
    public string $subject;
    public ?DateTimeImmutable $date;
    public array $from;
    public array $to;
    public array $cc;
    public ?string $messageId;

    /**
     * Combine one original Email with its stored analysis and compact history.
     * @param list<AnalyzedAttachment> $attachments
     * @param list<MailSummary> $history Chronological, excluding this message.
     * @param list<string> $missingReferences Referenced messages not available.
     * @example $mail = $analyzer->analyze($email, $client, $storage, 'incoming');
     * @see MailAnalyzer::analyze()
     */
    public function __construct(
        private Email $original,
        public ContentAnalysis $analysis,
        public MailSummary $metadata,
        private array $attachments,
        private array $history = [],
        public array $missingReferences = [],
        private ?\Closure $historyLoader = null,
    ) {
        $this->subject = $metadata->subject;
        $this->date = $metadata->date;
        $this->from = $metadata->from;
        $this->to = $metadata->to;
        $this->cc = $metadata->cc;
        $this->messageId = $metadata->messageId;
    }

    /**
     * Return the original decoded text body, not an AI rewrite.
     * @example $request = $mail->getContent();
     * @see Email::body()
     */
    public function getContent(): string
    {
        return $this->original->body()->text();
    }

    /**
     * Return the original decoded HTML body when present, otherwise plain text.
     * This is NOT an RFC-822/MIME dump. HTML remains untrusted and must not be
     * rendered unsanitized; this getter never performs I/O.
     * @example $originalBody = $mail->getRawContent();
     * @see self::getOriginalMail()
     */
    public function getRawContent(): string
    {
        return $this->original->body()->html() ?? $this->original->body()->text();
    }

    /**
     * Return the provider's immutable Email snapshot for advanced integrations.
     * @example $source = $mail->getOriginalMail();
     * @see Email
     */
    public function getOriginalMail(): Email
    {
        return $this->original;
    }

    /**
     * Return the saved short interpretation without a new model request.
     * @example $summary = $mail->getSummary();
     * @see ContentAnalysis
     */
    public function getSummary(): string
    {
        return $this->analysis->summary;
    }

    /**
     * Return all attachments, optionally restricted to a configured class ID.
     * An enum value is compared to its backing value; returned arrays are copies.
     * @return list<AnalyzedAttachment>
     * @example $cvs = $mail->getAttachments(DocumentType::Cv);
     * @see AnalyzedAttachment
     */
    public function getAttachments(string|BackedEnum|null $classification = null): array
    {
        $id = $classification instanceof BackedEnum ? (string) $classification->value : $classification;
        return $id === null ? $this->attachments : array_values(array_filter(
            $this->attachments,
            static fn (AnalyzedAttachment $attachment): bool => $attachment->analysis->classification === $id,
        ));
    }

    /**
     * Resolve an attachment by stable ID, including duplicate filenames.
     * @throws \OutOfBoundsException When this mail has no such attachment.
     * @example $cv = $mail->getAttachment('attachment-1');
     * @see self::getAttachments()
     */
    public function getAttachment(string $id): AnalyzedAttachment
    {
        foreach ($this->attachments as $attachment) {
            if ($attachment->id === $id) {
                return $attachment;
            }
        }
        throw new \OutOfBoundsException('Unknown attachment ' . $id . ' in mail ' . $this->metadata->id);
    }

    /**
     * Return known conversation messages oldest first; raw files are excluded.
     * Missing referenced messages are exposed in missingReferences, never invented.
     * @return list<MailSummary>
     * @example foreach ($mail->getHistory() as $entry) { $date = $entry->date; }
     * @see MailSummary
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * Load one previously indexed message with full attachment access on demand.
     * Only IDs already present in this mail's history are accepted. This is an
     * explicit server read, not part of routing; existing analyses are reused.
     * A missing/moved backing mail or changed content throws rather than silently
     * substituting another message. Normal provider errors propagate, and a
     * missing analysis cache may require another AI request.
     *
     * @param string $id MailSummary::id, not an arbitrary IMAP reference.
     * @return self Full immutable historical snapshot.
     * @throws \OutOfBoundsException When the ID is not in this conversation.
     * @throws \LogicException When no history loader was configured.
     * @example $previous = $mail->loadPrevious($mail->getHistory()[0]->id);
     * @see self::getHistory()
     */
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

    /**
     * Build a summary-only conversation for classification, including this mail.
     * @return array<string,mixed> No raw bodies, attachment bytes or full extracts.
     * @example $context = $mail->routingContext();
     * @see MailActionMatcher::select()
     */
    public function routingContext(): array
    {
        $messages = [...$this->history, $this->metadata];
        usort($messages, static fn (MailSummary $a, MailSummary $b): int =>
            ($a->date ?? $a->observedAt) <=> ($b->date ?? $b->observedAt) ?: strcmp($a->id, $b->id)
        );
        return [
            'targetMessageId' => $this->metadata->id,
            'missingReferences' => $this->missingReferences,
            'messages' => array_map(static fn (MailSummary $entry): array => $entry->toArray(), $messages),
        ];
    }

    /**
     * Schedule a reply, optionally with original or generated attachments.
     * No send/flag mutation happens here; MailAutomation executes the returned
     * action, saving a draft unless a DraftSender was explicitly configured.
     * @param list<Attachment|AnalyzedAttachment> $attachments
     * @throws \InvalidArgumentException For an invalid attachment object.
     * @example return $mail->reply('Here is your CV.', [$updatedCv]);
     * @see MailActions::schedule()
     */
    public function reply(string $markdown, array $attachments = []): MailAction
    {
        $files = [];
        foreach ($attachments as $attachment) {
            $files[] = $attachment instanceof AnalyzedAttachment ? $attachment->toAttachment() : $attachment;
        }
        return MailActions::schedule()->sendReply($markdown, $files);
    }
}
