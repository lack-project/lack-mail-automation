<?php
declare(strict_types=1);
namespace Lack\MailAutomation\Test;

use Lack\MailAutomation\MailAutomation;
use PDO;
use Phore\MailClient\MailClient;
use PHPUnit\Framework\TestCase;

final class StoragePathTest extends TestCase
{
    public function testSqliteFilePathKeepsExistingTablesAndAddsAutomationState(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mail-automation-');
        self::assertNotFalse($path);

        try {
            $database = new PDO('sqlite:' . $path);
            $database->exec('CREATE TABLE application_data (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
            $database->exec("INSERT INTO application_data(value) VALUES ('keep-me')");
            unset($database);

            $client = new MailClient(new TestSyncTransport(), 'path-storage-account', from: 'me@example.org');
            new MailAutomation(client: $client, storage: $path);

            $check = new PDO('sqlite:' . $path);
            self::assertSame('keep-me', $check->query('SELECT value FROM application_data')->fetchColumn());

            $tables = $check
                ->query("SELECT name FROM sqlite_master WHERE type = 'table'")
                ->fetchAll(PDO::FETCH_COLUMN);

            self::assertContains('application_data', $tables);
            self::assertContains('automation_state', $tables);
            self::assertContains('contacts', $tables);
            self::assertContains('metadata', $tables);
            self::assertSame(
                'path-storage-account',
                $check->query("SELECT v FROM automation_state WHERE k = 'account'")->fetchColumn(),
            );
        } finally {
            @unlink($path);
        }
    }
}
