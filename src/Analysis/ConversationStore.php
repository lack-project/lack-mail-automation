<?php

declare(strict_types=1);

namespace Lack\MailAutomation\Analysis;

use DateTimeImmutable;
use InvalidArgumentException;
use Lack\MailAutomation\AutomationStorage;
use RuntimeException;

/**
 * Persistence boundary for metadata and files attached to a conversation scope.
 *
 * Applications may provide a different implementation without changing actions.
 */
interface ConversationStore
{
    public function get(string $scopeId, string $key, mixed $default = null): mixed;

    public function set(string $scopeId, string $key, mixed $value): void;

    /** @return array<string,mixed> */
    public function all(string $scopeId): array;

    public function getFile(string $scopeId, string $name): ?ConversationFile;

    public function putFile(string $scopeId, string $name, string $content, ?string $mediaType = null): ConversationFile;

    /** @return array<string,ConversationFileInfo> */
    public function files(string $scopeId): array;
}

/**
 * Default conversation store backed by AutomationStorage.
 *
 * With the standard SqliteStorage, metadata and file bytes live in the same
 * SQLite database. Files are base64-encoded inside the metadata value because
 * AutomationStorage itself deliberately stores JSON values.
 */
final readonly class AutomationStorageConversationStore implements ConversationStore
{
    public function __construct(private AutomationStorage $storage)
    {
    }

    public function get(string $scopeId, string $key, mixed $default = null): mixed
    {
        return $this->storage->metadataGet('conversation', $scopeId, $key, $default);
    }

    public function set(string $scopeId, string $key, mixed $value): void
    {
        $this->storage->metadataSet('conversation', $scopeId, $key, $value);
    }

    public function all(string $scopeId): array
    {
        return $this->storage->metadataAll('conversation', $scopeId);
    }

    public function getFile(string $scopeId, string $name): ?ConversationFile
    {
        $data = $this->storage->metadataGet('conversation-file', $scopeId, self::normalizeName($name));
        if ($data === null) {
            return null;
        }

        $content = base64_decode($data['content'], true);
        if ($content === false) {
            throw new RuntimeException('Stored conversation file is not valid base64: ' . $name);
        }

        return new ConversationFile(
            name: $data['name'],
            content: $content,
            mediaType: $data['mediaType'],
            modifiedAt: new DateTimeImmutable($data['modifiedAt']),
        );
    }

    public function putFile(string $scopeId, string $name, string $content, ?string $mediaType = null): ConversationFile
    {
        $name = self::normalizeName($name);
        $file = new ConversationFile($name, $content, $mediaType, new DateTimeImmutable());

        $this->storage->metadataSet('conversation-file', $scopeId, $name, [
            'name' => $file->name,
            'content' => base64_encode($file->content),
            'mediaType' => $file->mediaType,
            'modifiedAt' => $file->modifiedAt->format(DATE_ATOM),
        ]);

        return $file;
    }

    public function files(string $scopeId): array
    {
        $result = [];
        foreach ($this->storage->metadataAll('conversation-file', $scopeId) as $name => $data) {
            $content = base64_decode($data['content'], true);
            if ($content === false) {
                throw new RuntimeException('Stored conversation file is not valid base64: ' . $name);
            }
            $result[$name] = new ConversationFileInfo(
                name: $data['name'],
                mediaType: $data['mediaType'],
                size: strlen($content),
                modifiedAt: new DateTimeImmutable($data['modifiedAt']),
            );
        }

        return $result;
    }

    private static function normalizeName(string $name): string
    {
        $name = trim(str_replace('\\', '/', $name), '/');
        if ($name === '' || str_contains($name, '../') || str_contains($name, '/..') || str_contains($name, "\0")) {
            throw new InvalidArgumentException('Conversation file name must be a relative logical path without traversal.');
        }

        return $name;
    }
}

final readonly class ConversationFile
{
    public function __construct(
        public string $name,
        public string $content,
        public ?string $mediaType,
        public DateTimeImmutable $modifiedAt,
    ) {
    }
}

final readonly class ConversationFileInfo
{
    public function __construct(
        public string $name,
        public ?string $mediaType,
        public int $size,
        public DateTimeImmutable $modifiedAt,
    ) {
    }
}

/**
 * Convenient scoped view used by actions.
 *
 * The default scope is the current mail thread. `MailContent::scopeFor()`
 * can intentionally select a recipient/customer scope spanning several threads.
 */
final readonly class ConversationScope
{
    public function __construct(
        public string $id,
        private ConversationStore $store,
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($this->id, $key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->store->set($this->id, $key, $value);
    }

    public function all(): array
    {
        return $this->store->all($this->id);
    }

    public function getFile(string $name): ?ConversationFile
    {
        return $this->store->getFile($this->id, $name);
    }

    public function putFile(string $name, string $content, ?string $mediaType = null): ConversationFile
    {
        return $this->store->putFile($this->id, $name, $content, $mediaType);
    }

    public function hasFile(string $name): bool
    {
        return $this->getFile($name) !== null;
    }

    public function fileModifiedAt(string $name): ?DateTimeImmutable
    {
        return $this->getFile($name)?->modifiedAt;
    }

    public function files(): array
    {
        return $this->store->files($this->id);
    }

    /** @return array<string,mixed> Metadata plus file inventory, never file bytes. */
    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'metadata' => $this->all(),
            'files' => array_map(
                static fn (ConversationFileInfo $file): array => [
                    'name' => $file->name,
                    'mediaType' => $file->mediaType,
                    'size' => $file->size,
                    'modifiedAt' => $file->modifiedAt->format(DATE_ATOM),
                ],
                $this->files(),
            ),
        ];
    }
}
