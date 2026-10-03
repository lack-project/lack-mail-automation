<?php

declare(strict_types=1);

namespace Lack\MailAutomation\Test;

use DateTimeImmutable;
use Lack\MailAutomation\Analysis\ConversationFile;
use Lack\MailAutomation\Analysis\ConversationFileInfo;
use Lack\MailAutomation\Analysis\ConversationScope;
use Lack\MailAutomation\Analysis\ConversationStore;
use Lack\MailAutomation\Analysis\MailActionMatcher;
use Lack\MailAutomation\Analysis\AnalyzedMail;
use Lack\MailAutomation\Analysis\ContentAnalysis;
use Lack\MailAutomation\Analysis\MailSummary;
use Lack\MailAutomation\Attributes\OnMailAction;
use Lack\MailAutomation\Folder;
use Lack\MailAutomation\MailAction;
use Lack\MailAutomation\MailActions;
use Lack\MailAutomation\MailContext;
use Phore\MailClient\Email;
use PHPUnit\Framework\TestCase;

#[OnMailAction(
    when: [GuardOnlyAction::class, 'accepts'],
    folder: Folder::Inbox,
)]
final class GuardOnlyAction
{
    public static function accepts(AnalyzedMail $mail, MailContext $context): bool
    {
        return true;
    }

    public function __invoke(AnalyzedMail $mail, MailContext $context): MailAction
    {
        return MailActions::complete();
    }
}

#[OnMailAction(condition: 'The current message asks for a profile update.')]
final class AiConditionAction
{
    public function __invoke(AnalyzedMail $mail, MailContext $context): MailAction
    {
        return MailActions::complete();
    }
}

final class MemoryConversationStore implements ConversationStore
{
    public array $metadata = [];
    public array $fileData = [];

    public function get(string $scopeId, string $key, mixed $default = null): mixed
    {
        return $this->metadata[$scopeId][$key] ?? $default;
    }

    public function set(string $scopeId, string $key, mixed $value): void
    {
        $this->metadata[$scopeId][$key] = $value;
    }

    public function all(string $scopeId): array
    {
        return $this->metadata[$scopeId] ?? [];
    }

    public function getFile(string $scopeId, string $name): ?ConversationFile
    {
        return $this->fileData[$scopeId][$name] ?? null;
    }

    public function putFile(string $scopeId, string $name, string $content, ?string $mediaType = null): ConversationFile
    {
        return $this->fileData[$scopeId][$name] = new ConversationFile(
            $name,
            $content,
            $mediaType,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    public function files(string $scopeId): array
    {
        $out = [];
        foreach ($this->fileData[$scopeId] ?? [] as $name => $file) {
            $out[$name] = new ConversationFileInfo(
                $name,
                $file->mediaType,
                strlen($file->content),
                $file->modifiedAt,
            );
        }

        return $out;
    }
}

final class AiMailActionsTest extends TestCase
{
    public function testClassLevelActionsAndStaticGuardAreRegistered(): void
    {
        $matcher = new MailActionMatcher([new GuardOnlyAction(), new AiConditionAction()]);

        self::assertSame([Folder::Inbox, Folder::Inbox], $matcher->folders());
    }

    public function testConversationScopeStoresMetadataAndFilesThroughInterface(): void
    {
        $store = new MemoryConversationStore();
        $scope = new ConversationScope('applicant:test@example.org', $store);

        $scope->set('stage', 'profile');
        $file = $scope->putFile('profile.md', '# Profile', 'text/markdown');

        self::assertSame('profile', $scope->get('stage'));
        self::assertTrue($scope->hasFile('profile.md'));
        self::assertSame('# Profile', $scope->getFile('profile.md')?->content);
        self::assertSame($file->modifiedAt, $scope->fileModifiedAt('profile.md'));
        self::assertSame(9, $scope->files()['profile.md']->size);
    }

    public function testMailAndConversationPromptsExposeSchemaAndData(): void
    {
        $current = $this->analyzedMail();

        $mailPrompt = $current->prompt('incomingEmail');
        self::assertSame('incomingEmail', $mailPrompt->alias());
        self::assertSame('object', $mailPrompt->jsonSchema()['type']);
        self::assertSame('Need help', $mailPrompt->data()['subject']);
        self::assertSame('Current body', $mailPrompt->data()['content']);

        $conversation = $current->conversationPrompt();
        self::assertSame('conversation', $conversation->alias());
        self::assertSame('object', $conversation->jsonSchema()['type']);
        self::assertCount(2, $conversation->data()['messages']);
        self::assertSame('Previous body', $conversation->data()['messages'][0]['content']);
        self::assertSame('Current body', $conversation->data()['messages'][1]['content']);
    }

    public function testActionNeedsConditionOrGuard(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new OnMailAction();
    }

    private function analyzedMail(): AnalyzedMail
    {
        $store = new MemoryConversationStore();
        $scope = new ConversationScope('thread-1', $store);
        $previous = new MailSummary(
            id: 'previous',
            messageId: '<previous@example.org>',
            subject: 'Need help',
            from: ['me@example.org'],
            to: ['user@example.org'],
            cc: [],
            date: new DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            observedAt: new DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            direction: 'outgoing',
            references: [],
            inReplyTo: null,
            content: 'Previous body',
            summary: 'Previous message',
            attachments: [],
            complete: true,
        );
        $current = new MailSummary(
            id: 'current',
            messageId: '<current@example.org>',
            subject: 'Need help',
            from: ['user@example.org'],
            to: ['me@example.org'],
            cc: [],
            date: new DateTimeImmutable('2026-01-01T10:00:00+00:00'),
            observedAt: new DateTimeImmutable('2026-01-01T10:00:00+00:00'),
            direction: 'incoming',
            references: ['<previous@example.org>'],
            inReplyTo: '<previous@example.org>',
            content: 'Current body',
            summary: 'Current message',
            attachments: [],
            complete: true,
        );

        return new AnalyzedMail(
            original: (new Email(from: 'user@example.org', to: 'me@example.org', subject: 'Need help'))
                ->withMarkdown('Current body'),
            analysis: new ContentAnalysis('Current message', 'Current body'),
            metadata: $current,
            attachments: [],
            history: [$previous],
            missingReferences: [],
            conversation: $scope,
            scopeStore: $store,
        );
    }
}
