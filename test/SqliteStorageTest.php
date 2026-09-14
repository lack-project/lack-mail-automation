<?php
declare(strict_types=1);
namespace Lack\MailAutomation\Test;

use Lack\MailAutomation\MailActions;
use Lack\MailAutomation\SqliteStorage;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteStorageTest extends TestCase
{
    public function testContactAliasesAndMetadataPersistImmediately(): void
    {
        $storage = new SqliteStorage(new PDO('sqlite::memory:'));
        $storage->bindAccount('account-1');

        $contact = $storage->contacts()->create('anna@old.example', 'Anna', 'verified_reply');
        $contact->metadata->set('classification', 'b2b');
        $alias = $contact->addAlias('anna@new.example', 'Anna neu', 'verified_reply');
        $contact->setPrimaryEmail($alias->email);

        $reloaded = $storage->contacts()->findByEmail('anna@new.example');
        self::assertNotNull($reloaded);
        self::assertSame($contact->id, $reloaded->id);
        self::assertSame('anna@new.example', $reloaded->primaryEmail);
        self::assertSame('b2b', $reloaded->metadata->get('classification'));
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
        MailActions::create()->addFlag('phore_processed');
    }
}
