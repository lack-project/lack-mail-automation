<?php
declare(strict_types=1);
namespace Lack\MailAutomation\Test;

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

final class ManagedFolderMoveTest extends TestCase
{
    public function testMoveToUsesManagedFolderAlias(): void
    {
        [$automation, $transport] = $this->fixture(['customers'=>'Customers']);
        $transport->addMessage('INBOX',1,$this->headers('managed@example.org'));
        $automation->register(
            Folder::Inbox,
            static fn(Email $mail, MailContext $context): bool => true,
            static fn(Email $mail, MailContext $context): MailAction => MailActions::moveTo('customers'),
            automationId:'managed-move',
        );

        $report = $automation->run();

        self::assertTrue($report->successful());
        self::assertArrayNotHasKey(1,$transport->messages['INBOX']);
        self::assertContains(MailAutomation::PROCESSED_FLAG,$transport->messages['Customers'][1]);
    }

    public function testUnknownManagedFolderAliasReportsConfigHint(): void
    {
        [$automation, $transport] = $this->fixture();
        $transport->addMessage('INBOX',1,$this->headers('unknown-alias@example.org'));
        $automation->register(
            Folder::Inbox,
            static fn(Email $mail, MailContext $context): bool => true,
            static fn(Email $mail, MailContext $context): MailAction => MailActions::moveTo('costumers'),
            automationId:'unknown-alias',
        );

        $report = $automation->run();

        self::assertFalse($report->successful());
        self::assertStringContainsString('Unknown managed folder alias "costumers". Define it in mailbox config "managedFolders".',$report->errors()[0]->error->getMessage());
        self::assertArrayHasKey(1,$transport->messages['INBOX']);
    }

    public function testMoveToRawUsesExactExistingFolderName(): void
    {
        [$automation, $transport] = $this->fixture();
        $transport->createFolder('Legacy/Exact');
        $transport->addMessage('INBOX',1,$this->headers('raw@example.org'));
        $automation->register(
            Folder::Inbox,
            static fn(Email $mail, MailContext $context): bool => true,
            static fn(Email $mail, MailContext $context): MailAction => MailActions::moveToRaw('Legacy/Exact'),
            automationId:'raw-move',
        );

        $report = $automation->run();

        self::assertTrue($report->successful());
        self::assertArrayNotHasKey(1,$transport->messages['INBOX']);
        self::assertContains(MailAutomation::PROCESSED_FLAG,$transport->messages['Legacy/Exact'][1]);
    }

    /** @return array{MailAutomation,ManagedFolderSyncTransport} */
    private function fixture(array $managedFolders = []): array
    {
        $transport = new ManagedFolderSyncTransport();
        $client = new MailClient($transport,'managed-folder-test',from:'me@example.org',managedFolders:$managedFolders);
        return [new MailAutomation($client,new PDO('sqlite::memory:')),$transport];
    }

    private function headers(string $messageId): string
    {
        return "From: sender@example.org\r\nTo: me@example.org\r\nSubject: Test\r\nMessage-ID: <{$messageId}>\r\n\r\n";
    }
}

final class ManagedFolderSyncTransport implements SyncTransport
{
    public string $folder = 'INBOX';
    public int $validity = 7;
    /** @var array<string,array<int,list<string>>> */
    public array $messages = ['INBOX'=>[],'Sent'=>[]];
    /** @var array<string,array<int,string>> */
    private array $headers = ['INBOX'=>[],'Sent'=>[]];

    public function addMessage(string $folder, int $uid, string $headers, array $flags = []): void
    {
        $this->messages[$folder] ??= [];
        $this->headers[$folder] ??= [];
        $this->messages[$folder][$uid] = $flags;
        $this->headers[$folder][$uid] = $headers;
    }

    public function select(string $folder, bool $write = false): array
    {
        if (!isset($this->messages[$folder])) { throw new \RuntimeException('Missing folder: ' . $folder); }
        $this->folder = $folder;
        return ['uidvalidity'=>$this->validity];
    }

    public function folderExists(string $folder): bool { return isset($this->messages[$folder]); }

    public function createFolder(string $folder): void
    {
        $this->messages[$folder] ??= [];
        $this->headers[$folder] ??= [];
    }

    public function search(array $criteria): array { return array_keys($this->messages[$this->folder]); }

    public function syncFlags(array $uids): array
    { return array_intersect_key($this->messages[$this->folder],array_flip($uids)); }

    public function metadata(int $uid): array
    {
        if (!isset($this->messages[$this->folder][$uid])) { throw new \RuntimeException('Message no longer exists.'); }
        return ['UID'=>$uid,'FLAGS'=>$this->messages[$this->folder][$uid],'BODYSTRUCTURE'=>['TEXT','PLAIN',['CHARSET','UTF-8'],null,null,'7BIT',0,0]];
    }

    public function part(int $uid, string $section, int $maxBytes): string
    { return $section === 'HEADER' ? ($this->headers[$this->folder][$uid] ?? '') : ''; }

    public function append(string $folder, string $mime): void { throw new \LogicException('Unexpected append.'); }

    public function flag(int $uid, string $flag, bool $add): void
    {
        $flags = $this->messages[$this->folder][$uid] ?? throw new \RuntimeException('Message no longer exists.');
        if ($add && !in_array($flag,$flags,true)) { $flags[] = $flag; }
        if (!$add) { $flags = array_values(array_filter($flags,static fn(string $known): bool => $known !== $flag)); }
        $this->messages[$this->folder][$uid] = $flags;
    }

    public function move(int $uid, string $folder): array
    {
        $source = $this->folder;
        if (!isset($this->messages[$source][$uid]) || !isset($this->messages[$folder])) { throw new \RuntimeException('Cannot move message.'); }
        $this->messages[$folder][$uid] = $this->messages[$source][$uid];
        $this->headers[$folder][$uid] = $this->headers[$source][$uid];
        unset($this->messages[$source][$uid],$this->headers[$source][$uid]);
        return [$this->validity,$uid];
    }
}
