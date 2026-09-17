<?php
declare(strict_types=1);
namespace Lack\MailAutomation\Test;

use Lack\MailAutomation\ContactResolutionStatus;
use Lack\MailAutomation\Folder;
use Lack\MailAutomation\MailAction;
use Lack\MailAutomation\MailActions;
use Lack\MailAutomation\MailAutomation;
use Lack\MailAutomation\MailContext;
use PDO;
use Phore\MailClient\Email;
use Phore\MailClient\Internal\SyncTransport;
use Phore\MailClient\MailClient;
use PHPUnit\Framework\TestCase;

final class MailAutomationTest extends TestCase
{
    public function testNormalIncomingFlowRunsRuleAndMarksMessageProcessed(): void
    {
        [$automation, $transport] = $this->fixture();
        $transport->addMessage('INBOX', 1, $this->headers(
            from: 'customer@example.org',
            to: 'me@example.org',
            messageId: 'incoming-1@example.org',
        ));

        $calls = 0;
        $automation->register(
            Folder::Inbox,
            static fn(Email $mail, MailContext $context): bool => true,
            static function (Email $mail, MailContext $context) use (&$calls): MailAction {
                $calls++;
                self::assertSame('incoming', $context->direction);
                self::assertSame(ContactResolutionStatus::Unknown, $context->contactResolution->status);
                return MailActions::schedule()->addFlag('classified');
            },
            automationId: 'normal-flow',
        );

        $report = $automation->run();

        self::assertTrue($report->successful());
        self::assertSame(1, $report->processed);
        self::assertSame(1, $calls);
        self::assertContains('classified', $transport->messages['INBOX'][1]);
        self::assertContains(MailAutomation::PROCESSED_FLAG, $transport->messages['INBOX'][1]);

        $second = $automation->run();
        self::assertTrue($second->successful());
        self::assertSame(0, $second->processed);
        self::assertSame(1, $second->skipped);
        self::assertSame(1, $calls);
    }

    public function testProcessingErrorContainsOperationAndMessageContext(): void
    {
        [$automation, $transport] = $this->fixture();
        $transport->addMessage('INBOX', 11, $this->headers(
            from: 'sender@example.org',
            to: 'me@example.org',
            messageId: 'failed@example.org',
        ));

        $cause = new \RuntimeException('request failed: Empty response');
        $automation->register(
            Folder::Inbox,
            static fn(Email $mail, MailContext $context): bool => true,
            static function (Email $mail, MailContext $context) use ($cause): MailAction {
                throw $cause;
            },
            automationId: 'failing-rule',
        );

        $report = $automation->run();

        self::assertFalse($report->successful());
        self::assertCount(1, $report->errors());
        self::assertContains(MailAutomation::ERROR_FLAG, $transport->messages['INBOX'][11]);
        self::assertNotContains(MailAutomation::PROCESSED_FLAG, $transport->messages['INBOX'][11]);
        $error = $report->errors()[0]->error;
        self::assertSame($cause, $error->getPrevious());
        self::assertSame(
            'Message processing failed: request failed: Empty response Processing context: operation="process incoming message", folder="INBOX", message-id="<failed@example.org>", from="sender@example.org", date="2026-02-19T10:15:00+00:00", subject="Test failed@example.org".',
            $error->getMessage(),
        );
    }

    public function testDryRunIsVisibleInContextAndCanBeRepeatedWithoutCheckpoint(): void
    {
        [$automation, $transport] = $this->fixture();
        $transport->addMessage('INBOX', 1, $this->headers(
            from: 'customer@example.org',
            to: 'me@example.org',
            messageId: 'dry-run@example.org',
        ));

        $calls = 0;
        $automation->register(
            Folder::Inbox,
            static fn(Email $mail, MailContext $context): bool => true,
            static function (Email $mail, MailContext $context) use (&$calls): MailAction {
                self::assertTrue($context->dryRun);
                $calls++;
                return MailActions::complete();
            },
            automationId: 'dry-run',
        );

        self::assertSame(1, $automation->run(dryRun: true)->processed);
        self::assertSame(1, $automation->run(dryRun: true)->processed);
        self::assertSame(2, $calls);
        self::assertNotContains(MailAutomation::PROCESSED_FLAG, $transport->messages['INBOX'][1]);
        self::assertNull($automation->storage()->cursor('INBOX'));
    }

    public function testVerifiedReplyCreatesContactAndAliasFromSentEvidence(): void
    {
        [$automation, $transport] = $this->fixture();
        $transport->addMessage('Sent', 5, $this->headers(
            from: 'me@example.org',
            to: 'old-address@example.org',
            messageId: 'sent-1@example.org',
        ));

        $seenStatus = null;
        $automation->register(
            Folder::Inbox,
            static fn(Email $mail, MailContext $context): bool => true,
            static function (Email $mail, MailContext $context) use (&$seenStatus): MailAction {
                $seenStatus = $context->contactResolution->status;
                return MailActions::complete();
            },
            automationId: 'reply-flow',
        );

        $initial = $automation->run();
        self::assertTrue($initial->successful());
        self::assertSame(1, $initial->indexedSent);

        $transport->addMessage('INBOX', 10, $this->headers(
            from: 'new-address@example.org',
            to: 'me@example.org',
            messageId: 'reply-1@example.org',
            inReplyTo: 'sent-1@example.org',
        ));

        $reply = $automation->run();

        self::assertTrue($reply->successful());
        self::assertSame(ContactResolutionStatus::ContactCreated, $seenStatus);
        $byOldAddress = $automation->storage()->contacts()->findByEmail('old-address@example.org');
        $byNewAddress = $automation->storage()->contacts()->findByEmail('new-address@example.org');
        self::assertNotNull($byOldAddress);
        self::assertNotNull($byNewAddress);
        self::assertSame($byOldAddress->id, $byNewAddress->id);
    }

    public function testActionRequiredBlocksFurtherProcessingUntilFlagIsCleared(): void
    {
        [$automation, $transport] = $this->fixture();
        $transport->addMessage('INBOX', 2, $this->headers(
            from: 'unknown@example.org',
            to: 'me@example.org',
            messageId: 'reply-missing@example.org',
            inReplyTo: 'missing@example.org',
        ));

        $calls = 0;
        $automation->register(
            Folder::Inbox,
            static fn(Email $mail, MailContext $context): bool => true,
            static function (Email $mail, MailContext $context) use (&$calls): MailAction {
                $calls++;
                self::assertTrue($context->contactResolution->needsReview());
                self::assertSame(ContactResolutionStatus::OutgoingMissing, $context->contactResolution->status);
                return MailActions::actionRequired();
            },
            automationId: 'missing-outgoing',
        );

        $first = $automation->run();
        self::assertTrue($first->successful());
        self::assertSame(1, $calls);
        self::assertContains(MailAutomation::ACTION_REQUIRED_FLAG, $transport->messages['INBOX'][2]);
        self::assertNotContains(MailAutomation::PROCESSED_FLAG, $transport->messages['INBOX'][2]);

        $transport->select('INBOX', true);
        $transport->flag(2, '\\Flagged', true);
        $blocked = $automation->run();
        self::assertTrue($blocked->successful());
        self::assertSame(1, $blocked->skipped);
        self::assertSame(1, $calls);

        $transport->select('INBOX', true);
        $transport->flag(2, MailAutomation::ACTION_REQUIRED_FLAG, false);
        $retried = $automation->run();
        self::assertTrue($retried->successful());
        self::assertSame(2, $calls);
        self::assertContains(MailAutomation::ACTION_REQUIRED_FLAG, $transport->messages['INBOX'][2]);
    }

    public function testUnmatchedMessageStaysUnprocessedAndFlagChangeRetriesIt(): void
    {
        [$automation, $transport] = $this->fixture();
        $transport->addMessage('INBOX', 4, $this->headers(
            from: 'customer@example.org',
            to: 'me@example.org',
            messageId: 'unmatched@example.org',
        ));

        $matches = false;
        $calls = 0;
        $automation->register(
            Folder::Inbox,
            static function (Email $mail, MailContext $context) use (&$matches): bool {
                return $matches;
            },
            static function (Email $mail, MailContext $context) use (&$calls): MailAction {
                $calls++;
                return MailActions::complete();
            },
            automationId: 'retry-after-flag-change',
        );

        $first = $automation->run();

        self::assertTrue($first->successful());
        self::assertSame(0, $first->processed);
        self::assertSame(1, $first->skipped);
        self::assertSame(0, $calls);
        self::assertNotContains(MailAutomation::PROCESSED_FLAG, $transport->messages['INBOX'][4]);

        $matches = true;
        $transport->select('INBOX', true);
        $transport->flag(4, '\\Flagged', true);

        $second = $automation->run();

        self::assertTrue($second->successful());
        self::assertSame(1, $second->processed);
        self::assertSame(1, $calls);
        self::assertContains(MailAutomation::PROCESSED_FLAG, $transport->messages['INBOX'][4]);
    }

    public function testPassFallsThroughByPriorityAndDuplicateIdsAreRejected(): void
    {
        [$automation, $transport] = $this->fixture();
        $transport->addMessage('INBOX', 3, $this->headers(
            from: 'customer@example.org',
            to: 'me@example.org',
            messageId: 'priority@example.org',
        ));

        $calls = [];
        $automation->register(
            Folder::Inbox,
            static fn(Email $mail, MailContext $context): bool => true,
            static function (Email $mail, MailContext $context) use (&$calls): MailAction {
                $calls[] = 'high';
                return MailActions::pass();
            },
            priority: 100,
            automationId: 'high',
        );
        $automation->register(
            Folder::Inbox,
            static fn(Email $mail, MailContext $context): bool => true,
            static function (Email $mail, MailContext $context) use (&$calls): MailAction {
                $calls[] = 'normal';
                return MailActions::schedule()->addFlag('handled');
            },
            priority: 0,
            automationId: 'normal',
        );

        self::assertTrue($automation->run()->successful());
        self::assertSame(['high', 'normal'], $calls);
        self::assertContains('handled', $transport->messages['INBOX'][3]);

        $this->expectException(\InvalidArgumentException::class);
        $automation->register(
            Folder::Inbox,
            static fn(Email $mail, MailContext $context): bool => true,
            static fn(Email $mail, MailContext $context): MailAction => MailActions::complete(),
            automationId: 'normal',
        );
    }

    /** @return array{MailAutomation, TestSyncTransport} */
    private function fixture(): array
    {
        $transport = new TestSyncTransport();
        $client = new MailClient($transport, 'test-account', from: 'me@example.org');
        return [new MailAutomation($client, new PDO('sqlite::memory:')), $transport];
    }

    private function headers(
        string $from,
        string $to,
        string $messageId,
        ?string $inReplyTo = null,
    ): string {
        $headers = [
            'From: ' . $from,
            'To: ' . $to,
            'Subject: Test ' . $messageId,
            'Date: Thu, 19 Feb 2026 10:15:00 +0000',
            'Message-ID: <' . $messageId . '>',
        ];
        if ($inReplyTo !== null) {
            $headers[] = 'In-Reply-To: <' . $inReplyTo . '>';
        }
        return implode("\r\n", $headers) . "\r\n\r\n";
    }
}

final class TestSyncTransport implements SyncTransport
{
    public string $folder = 'INBOX';
    public int $validity = 7;
    /** @var array<string,array<int,list<string>>> */
    public array $messages = ['INBOX' => [], 'Sent' => []];
    /** @var array<string,array<int,string>> */
    private array $headers = ['INBOX' => [], 'Sent' => []];

    public function addMessage(string $folder, int $uid, string $headers, array $flags = []): void
    {
        $this->messages[$folder] ??= [];
        $this->headers[$folder] ??= [];
        $this->messages[$folder][$uid] = $flags;
        $this->headers[$folder][$uid] = $headers;
    }

    public function select(string $folder, bool $write = false): array
    {
        if (!isset($this->messages[$folder])) {
            throw new \RuntimeException('Missing folder: ' . $folder);
        }
        $this->folder = $folder;
        return ['uidvalidity' => $this->validity];
    }

    public function folderExists(string $folder): bool
    {
        return isset($this->messages[$folder]);
    }

    public function createFolder(string $folder): void
    {
        $this->messages[$folder] ??= [];
        $this->headers[$folder] ??= [];
    }

    public function search(array $criteria): array
    {
        return array_keys($this->messages[$this->folder]);
    }

    public function syncFlags(array $uids): array
    {
        return array_intersect_key($this->messages[$this->folder], array_flip($uids));
    }

    public function metadata(int $uid): array
    {
        if (!isset($this->messages[$this->folder][$uid])) {
            throw new \RuntimeException('Message no longer exists.');
        }
        return [
            'UID' => $uid,
            'FLAGS' => $this->messages[$this->folder][$uid],
            'BODYSTRUCTURE' => ['TEXT', 'PLAIN', ['CHARSET', 'UTF-8'], null, null, '7BIT', 0, 0],
        ];
    }

    public function part(int $uid, string $section, int $maxBytes): string
    {
        if ($section === 'HEADER') {
            return $this->headers[$this->folder][$uid] ?? '';
        }
        return '';
    }

    public function append(string $folder, string $mime): void
    {
        throw new \LogicException('Unexpected append.');
    }

    public function flag(int $uid, string $flag, bool $add): void
    {
        $flags = $this->messages[$this->folder][$uid] ?? throw new \RuntimeException('Message no longer exists.');
        if ($add) {
            if (!in_array($flag, $flags, true)) {
                $flags[] = $flag;
            }
        } else {
            $flags = array_values(array_filter($flags, static fn(string $known): bool => $known !== $flag));
        }
        $this->messages[$this->folder][$uid] = $flags;
    }

    public function move(int $uid, string $folder): array
    {
        throw new \LogicException('Unexpected move.');
    }
}
