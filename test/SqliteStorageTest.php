<?php
declare(strict_types=1);
namespace Lack\MailAutomation\Test;

use DateTimeImmutable;
use Lack\MailAutomation\HistoryFilter;
use Lack\MailAutomation\HistoryFilterResult;
use Lack\MailAutomation\MailActions;
use Lack\MailAutomation\MailAutomation;
use Lack\MailAutomation\SqliteStorage;
use PDO;
use Phore\MailClient\Body;
use Phore\MailClient\Email;
use PHPUnit\Framework\TestCase;

final class SqliteStorageTest extends TestCase
{
    public function testContactAliasesMetadataAndTagsPersistImmediately(): void
    {
        $storage = new SqliteStorage(new PDO('sqlite::memory:'));
        $storage->bindAccount('account-1');

        $contact = $storage->contacts()->create('anna@old.example', 'Anna', 'verified_reply');
        $contact->metadata->set('classification', 'b2b');
        $contact->setTag('vip');
        $contact->setTag('customer_group', 'a');
        $alias = $contact->addAlias('anna@new.example', 'Anna neu', 'verified_reply');
        $contact->setPrimaryEmail($alias->email);

        $reloaded = $storage->contacts()->findByEmail('anna@new.example');
        self::assertNotNull($reloaded);
        self::assertSame($contact->id, $reloaded->id);
        self::assertSame('anna@new.example', $reloaded->primaryEmail);
        self::assertSame('b2b', $reloaded->metadata->get('classification'));
        self::assertTrue($reloaded->hasTag('vip'));
        self::assertTrue($reloaded->hasTag('customer_group'));
        self::assertTrue($reloaded->hasTag('customer_group', 'a'));
        self::assertSame('a', $reloaded->getTagValue('customer_group'));

        $reloaded->setTag('customer_group', 'b');
        self::assertFalse($reloaded->hasTag('customer_group', 'a'));
        self::assertTrue($reloaded->hasTag('customer_group', 'b'));
        $reloaded->removeTag('vip');
        self::assertFalse($reloaded->hasTag('vip'));
        self::assertSame(['customer_group' => 'b'], $reloaded->tags());
    }

    public function testContactsCanBeIterated(): void
    {
        $storage = new SqliteStorage(new PDO('sqlite::memory:'));
        $storage->contacts()->create('bernd@example.com', 'Bernd');
        $storage->contacts()->create('anna@example.com', 'Anna');

        $emails = [];
        foreach ($storage->contacts() as $contact) { $emails[] = $contact->primaryEmail; }

        self::assertSame(['anna@example.com', 'bernd@example.com'], $emails);
    }

    public function testMailHistoryIsAlwaysNewestFirstAndFiltersPreserveOrder(): void
    {
        $storage = new SqliteStorage(new PDO('sqlite::memory:'));
        $contact = $storage->contacts()->create('anna@example.com', 'Anna');

        $old = $this->mail('old', 'Old notice', '2026-01-10T09:00:00+00:00');
        $new = $this->mail('new', 'Newsletter September', '2026-09-10T09:00:00+00:00');
        $middle = $this->mail('middle', 'Newsletter May', '2026-05-10T09:00:00+00:00');

        // Deliberately insert out of chronological order. The API contract is mail-date DESC, not insertion order.
        $storage->recordHistory($contact->id, $old, 'outgoing', 'Sent');
        $storage->recordHistory($contact->id, $new, 'outgoing', 'Sent');
        $storage->recordHistory($contact->id, $middle, 'outgoing', 'Sent');
        $storage->tagSet('message', $new->messageId(), 'newsletter', '2026-09');
        $storage->tagSet('message', $middle->messageId(), 'newsletter', '2026-05');

        self::assertSame(
            ['Newsletter September', 'Newsletter May', 'Old notice'],
            array_map(static fn($entry) => $entry->subject, iterator_to_array($contact->mailHistory())),
        );

        $newsletters = iterator_to_array($contact->mailHistory(0, HistoryFilter::tag('newsletter')));
        self::assertSame(['Newsletter September', 'Newsletter May'], array_map(static fn($entry) => $entry->subject, $newsletters));
        self::assertSame('2026-09', $newsletters[0]->getTagValue('newsletter'));

        self::assertSame(
            ['Newsletter September'],
            array_map(
                static fn($entry) => $entry->subject,
                iterator_to_array($contact->mailHistory(1, HistoryFilter::tag('newsletter'))),
            ),
        );

        self::assertSame(
            ['Newsletter September'],
            array_map(
                static fn($entry) => $entry->subject,
                iterator_to_array($contact->mailHistory(0, HistoryFilter::from(new DateTimeImmutable('2026-06-01T00:00:00+00:00')))),
            ),
        );

        self::assertSame(
            ['Newsletter May', 'Old notice'],
            array_map(
                static fn($entry) => $entry->subject,
                iterator_to_array($contact->mailHistory(0, HistoryFilter::to(new DateTimeImmutable('2026-06-01T00:00:00+00:00')))),
            ),
        );

        self::assertSame(
            ['Newsletter September', 'Newsletter May'],
            array_map(
                static fn($entry) => $entry->subject,
                iterator_to_array($contact->mailHistory(0, HistoryFilter::subjectContains('Newsletter'))),
            ),
        );
    }

    public function testCustomHistoryFilterCanStopIteration(): void
    {
        $storage = new SqliteStorage(new PDO('sqlite::memory:'));
        $contact = $storage->contacts()->create('anna@example.com', 'Anna');
        $storage->recordHistory($contact->id, $this->mail('one', 'One', '2026-09-10T09:00:00+00:00'), 'incoming', 'INBOX');
        $storage->recordHistory($contact->id, $this->mail('two', 'Two', '2026-08-10T09:00:00+00:00'), 'incoming', 'INBOX');
        $storage->recordHistory($contact->id, $this->mail('three', 'Three', '2026-07-10T09:00:00+00:00'), 'incoming', 'INBOX');

        $visited = [];
        $filter = HistoryFilter::callback(static function ($entry) use (&$visited): HistoryFilterResult {
            $visited[] = $entry->subject;
            return $entry->subject === 'Two' ? HistoryFilterResult::Stop : HistoryFilterResult::Match;
        });

        $result = iterator_to_array($contact->mailHistory(0, $filter));

        self::assertSame(['One'], array_map(static fn($entry) => $entry->subject, $result));
        self::assertSame(['One', 'Two'], $visited);
    }

    public function testStorageRejectsAnotherAccount(): void
    {
        $storage = new SqliteStorage(new PDO('sqlite::memory:'));
        $storage->bindAccount('account-1');
        $this->expectException(\RuntimeException::class);
        $storage->bindAccount('account-2');
    }

    public function testProcessedMarkerIsReserved(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MailActions::schedule()->addFlag(MailAutomation::DEFAULT_AUTOMATION_FLAGS['processed']);
    }

    private function mail(string $id, string $subject, string $date): Email
    {
        return Email::received(
            [
                'from' => ['sender@example.com'],
                'to' => ['anna@example.com'],
                'subject' => [$subject],
                'message-id' => ['<' . $id . '@example.com>'],
                'date' => [$date],
            ],
            new Body(''),
            [],
            $id,
            [],
        );
    }
}
