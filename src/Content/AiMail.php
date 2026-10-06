<?php

declare(strict_types=1);

namespace Lack\MailAutomation\Content;

use InvalidArgumentException;
use Lack\MailAutomation\MailAction;
use Lack\MailAutomation\MailActions;
use Phore\AiHarness\AiContext;
use Phore\AiHarness\Content\AiContent;
use Phore\AiHarness\Content\AiDocument;
use Phore\AiHarness\PromptType\FilePrompt;
use Phore\AiHarness\PromptType\PromptType;
use Phore\MailClient\Attachment;
use Phore\MailClient\Email;

/**
 * Immutable AI-generated outbound mail.
 *
 * AiMail is AI content itself, so generated mails can be referenced by ID or
 * alias in later prompts before they are sent. Calling send() only schedules
 * delivery; the normal MailAutomation dry-run and sender semantics still apply.
 */
final readonly class AiMail extends AiContent
{
    public const MODE_MAIL = 'mail';
    public const MODE_REPLY = 'reply';
    public const MODE_FORWARD = 'forward';

    /**
     * @param string|array|null $to Recipient for new/forward mails.
     * @param list<string> $aliases Human-readable prompt references.
     * @example $mail->ai_reply($prompt, aliases: ['initialReply'])->send();
     * @see MailContent::ai_reply()
     */
    public function __construct(
        string $markdown,
        public string $mode,
        public string|array|null $to = null,
        public ?string $subject = null,
        public bool $answerable = true,
        public string $reason = '',
        ?AiContext $context = null,
        ?string $id = null,
        array $aliases = [],
        string $instructions = '',
    ) {
        if (!in_array($mode, [self::MODE_MAIL, self::MODE_REPLY, self::MODE_FORWARD], true)) {
            throw new InvalidArgumentException('Unknown AI mail mode: ' . $mode);
        }

        parent::__construct(
            rawData: $markdown,
            fileName: 'mail.md',
            description: 'AI-generated outbound mail draft.',
            context: $context,
            id: $id,
            aliases: $aliases,
            instructions: $instructions,
        );
    }

    /**
     * Prepare delivery with one attachment without changing the AiMail content.
     *
     * @return AiMailDelivery Delivery builder containing this immutable mail.
     * @example return $draft->attach($pdf)->send();
     * @see send()
     */
    public function attach(AiDocument $attachment): AiMailDelivery
    {
        return new AiMailDelivery($this, [$attachment]);
    }

    /**
     * Schedule this generated mail for delivery.
     *
     * @param list<AiDocument> $attachments Attachments to send.
     * @return MailAction Scheduled mail action or actionRequired.
     * @example return $mail->ai_mail($prompt, to: 'user@example.org')->send();
     * @see MailActions::schedule()
     */
    public function send(array $attachments = []): MailAction
    {
        if (!$this->answerable || trim($this->rawData) === '') {
            return MailActions::actionRequired();
        }

        $files = self::normalizeAttachments($attachments);

        if ($this->mode === self::MODE_REPLY) {
            return MailActions::schedule()->sendReply($this->rawData, $files);
        }

        if ($this->to === null || $this->to === []) {
            return MailActions::actionRequired();
        }

        foreach ((array) $this->to as $recipient) {
            if (!is_string($recipient) || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                return MailActions::actionRequired();
            }
        }

        if ($this->subject === null || trim($this->subject) === '') {
            return MailActions::actionRequired();
        }

        $email = (new Email(to: $this->to, subject: $this->subject))
            ->withMarkdown($this->rawData);

        foreach ($files as $attachment) {
            $email = $email->attach($attachment);
        }

        return MailActions::schedule()->sendMail($email);
    }

    public function toPromptType(): PromptType
    {
        return new FilePrompt(
            $this->fileName ?? 'mail.md',
            $this->rawData,
            'text/markdown',
            alias: $this->id,
            instructions: $this->promptInstructions(),
            type: 'mail',
            allowInstructions: false,
        );
    }

    protected function recreate(
        string $rawData,
        ?AiContext $context = null,
        ?string $id = null,
    ): static {
        return new self(
            markdown: $rawData,
            mode: $this->mode,
            to: $this->to,
            subject: $this->subject,
            answerable: $this->answerable,
            reason: $this->reason,
            context: $context,
            id: $id,
            aliases: $this->aliases,
            instructions: $this->instructions,
        );
    }

    /** @param list<AiDocument> $attachments @return list<Attachment> */
    private static function normalizeAttachments(array $attachments): array
    {
        $files = [];

        foreach ($attachments as $attachment) {
            if (!$attachment instanceof AiDocument) {
                throw new InvalidArgumentException('AI mail attachments must be AiDocument objects.');
            }
            if ($attachment->fileName === null) {
                throw new InvalidArgumentException('AI mail attachment requires a filename.');
            }

            $files[] = Attachment::fromBytes(
                $attachment->fileName,
                $attachment->contentType,
                $attachment->rawData,
            );
        }

        return $files;
    }
}

/**
 * Immutable delivery builder for an AiMail plus attachments.
 */
final readonly class AiMailDelivery
{
    /** @param list<AiDocument> $attachments */
    public function __construct(
        public AiMail $mail,
        public array $attachments = [],
    ) {
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof AiDocument) {
                throw new InvalidArgumentException('AI mail attachments must be AiDocument objects.');
            }
        }
    }

    public function attach(AiDocument $attachment): self
    {
        return new self($this->mail, [...$this->attachments, $attachment]);
    }

    public function send(): MailAction
    {
        return $this->mail->send($this->attachments);
    }
}
