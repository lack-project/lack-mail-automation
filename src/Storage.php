<?php
declare(strict_types=1);
namespace Lack\MailAutomation;

use PDO;
use Phore\MailClient\Email;

interface AutomationStorage
{
    public function bindAccount(string $accountId): void;
    public function cursor(string $folder): ?string;
    public function saveCursor(string $folder, string $cursor): void;
    public function contacts(): Contacts;
    public function contactFindByEmail(string $email): ?Contact;
    public function contactFindById(string $id): ?Contact;
    public function contactCreate(string $email, ?string $name = null, string $source = 'manual'): Contact;
    public function contactSetName(string $id, string $name): void;
    public function contactAddAlias(string $id, string $email, ?string $name, string $source): ContactAlias;
    public function contactSetAliasName(string $id, string $email, ?string $name): void;
    public function contactSetPrimaryEmail(string $id, string $email): void;
    public function contactRemoveAlias(string $id, string $email): void;
    public function metadataGet(string $scope, string $scopeId, string $key, mixed $default = null): mixed;
    public function metadataSet(string $scope, string $scopeId, string $key, mixed $value): void;
    public function metadataAll(string $scope, string $scopeId): array;
    public function recordSent(Email $mail, string $folder, string $recipientEmail): void;
    public function sentEvidence(string $messageId): ?MatchedOutgoing;
    public function recordHistory(?string $contactId, Email $mail, string $direction, string $folder): void;
    public function historyForContact(string $contactId): array;
}

final class SqliteStorage implements AutomationStorage
{
    private Contacts $contactRepository;

    public function __construct(private PDO $pdo)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new \InvalidArgumentException('SqliteStorage requires a PDO SQLite connection.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->initialize();
        $this->contactRepository = new Contacts($this);
    }

    private function initialize(): void
    {
        foreach ([
            'CREATE TABLE IF NOT EXISTS automation_state (k TEXT PRIMARY KEY, v TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS contacts (id TEXT PRIMARY KEY, name TEXT NOT NULL, primary_email TEXT NOT NULL UNIQUE)',
            'CREATE TABLE IF NOT EXISTS contact_aliases (email TEXT PRIMARY KEY, contact_id TEXT NOT NULL, name TEXT NULL, source TEXT NOT NULL, FOREIGN KEY(contact_id) REFERENCES contacts(id))',
            'CREATE INDEX IF NOT EXISTS idx_contact_aliases_contact ON contact_aliases(contact_id)',
            'CREATE TABLE IF NOT EXISTS metadata (scope TEXT NOT NULL, scope_id TEXT NOT NULL, k TEXT NOT NULL, v TEXT NOT NULL, PRIMARY KEY(scope, scope_id, k))',
            'CREATE TABLE IF NOT EXISTS sent_evidence (message_id TEXT PRIMARY KEY, recipient_email TEXT NOT NULL, folder TEXT NOT NULL, server_id TEXT NOT NULL, sent_at TEXT NULL)',
            'CREATE TABLE IF NOT EXISTS mail_history (id INTEGER PRIMARY KEY AUTOINCREMENT, contact_id TEXT NULL, message_id TEXT NULL, direction TEXT NOT NULL, folder TEXT NOT NULL, server_id TEXT NULL, observed_at TEXT NOT NULL)',
        ] as $sql) { $this->pdo->exec($sql); }
    }

    public function bindAccount(string $accountId): void
    {
        $known = $this->state('account');
        if ($known !== null && !hash_equals($known, $accountId)) { throw new \RuntimeException('Automation storage belongs to another mail account.'); }
        if ($known === null) { $this->setState('account', $accountId); }
    }

    private function state(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT v FROM automation_state WHERE k = ?'); $stmt->execute([$key]);
        $value = $stmt->fetchColumn(); return $value === false ? null : (string)$value;
    }

    private function setState(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO automation_state(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v');
        $stmt->execute([$key,$value]);
    }

    public function cursor(string $folder): ?string { return $this->state('cursor:' . $folder); }
    public function saveCursor(string $folder, string $cursor): void { $this->setState('cursor:' . $folder, $cursor); }
    public function contacts(): Contacts { return $this->contactRepository; }

    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new \InvalidArgumentException('Invalid contact email.'); }
        return $email;
    }

    public function contactFindByEmail(string $email): ?Contact
    {
        $email = $this->normalizeEmail($email);
        $stmt = $this->pdo->prepare('SELECT contact_id FROM contact_aliases WHERE email = ?'); $stmt->execute([$email]);
        $id = $stmt->fetchColumn(); return $id === false ? null : $this->contactFindById((string)$id);
    }

    public function contactFindById(string $id): ?Contact
    {
        $stmt = $this->pdo->prepare('SELECT id,name,primary_email FROM contacts WHERE id = ?'); $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC); if ($row === false) { return null; }
        $aliases = [];
        $a = $this->pdo->prepare('SELECT email,name,source FROM contact_aliases WHERE contact_id = ? ORDER BY email'); $a->execute([$id]);
        while ($alias = $a->fetch(PDO::FETCH_ASSOC)) { $aliases[] = new ContactAlias($alias['email'], $alias['name'], $alias['source']); }
        return new Contact($row['id'], $this, $row['name'], $row['primary_email'], $aliases);
    }

    public function contactCreate(string $email, ?string $name = null, string $source = 'manual'): Contact
    {
        $email = $this->normalizeEmail($email);
        $existing = $this->contactFindByEmail($email); if ($existing !== null) { return $existing; }
        $id = 'contact-' . substr(hash('sha256', $email), 0, 12);
        if ($this->contactFindById($id) !== null) { throw new \RuntimeException('Contact ID collision.'); }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO contacts(id,name,primary_email) VALUES(?,?,?)')->execute([$id,$name ?? '',$email]);
            $this->pdo->prepare('INSERT INTO contact_aliases(email,contact_id,name,source) VALUES(?,?,?,?)')->execute([$email,$id,$name,$source]);
            $this->pdo->commit();
        } catch (\Throwable $e) { $this->pdo->rollBack(); throw $e; }
        return $this->contactFindById($id) ?? throw new \RuntimeException('Contact creation failed.');
    }

    public function contactSetName(string $id, string $name): void
    { $this->pdo->prepare('UPDATE contacts SET name=? WHERE id=?')->execute([$name,$id]); }

    public function contactAddAlias(string $id, string $email, ?string $name, string $source): ContactAlias
    {
        $email = $this->normalizeEmail($email);
        $known = $this->contactFindByEmail($email);
        if ($known !== null && $known->id !== $id) { throw new \RuntimeException('Alias belongs to another contact.'); }
        if ($known === null) {
            $this->pdo->prepare('INSERT INTO contact_aliases(email,contact_id,name,source) VALUES(?,?,?,?)')->execute([$email,$id,$name,$source]);
        }
        $stmt = $this->pdo->prepare('SELECT email,name,source FROM contact_aliases WHERE email=? AND contact_id=?'); $stmt->execute([$email,$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC); if ($row === false) { throw new \RuntimeException('Alias creation failed.'); }
        return new ContactAlias($row['email'],$row['name'],$row['source']);
    }

    public function contactSetAliasName(string $id, string $email, ?string $name): void
    {
        $email = $this->normalizeEmail($email);
        $stmt = $this->pdo->prepare('UPDATE contact_aliases SET name=? WHERE email=? AND contact_id=?'); $stmt->execute([$name,$email,$id]);
        if ($stmt->rowCount() === 0) { throw new \RuntimeException('Alias not found.'); }
    }

    public function contactSetPrimaryEmail(string $id, string $email): void
    {
        $email = $this->normalizeEmail($email);
        $stmt = $this->pdo->prepare('SELECT 1 FROM contact_aliases WHERE email=? AND contact_id=?'); $stmt->execute([$email,$id]);
        if ($stmt->fetchColumn() === false) { throw new \RuntimeException('Primary email must already be an alias.'); }
        $this->pdo->prepare('UPDATE contacts SET primary_email=? WHERE id=?')->execute([$email,$id]);
    }

    public function contactRemoveAlias(string $id, string $email): void
    {
        $email = $this->normalizeEmail($email);
        $contact = $this->contactFindById($id) ?? throw new \RuntimeException('Contact not found.');
        if ($contact->primaryEmail === $email) { throw new \RuntimeException('Cannot remove the primary email alias.'); }
        $this->pdo->prepare('DELETE FROM contact_aliases WHERE email=? AND contact_id=?')->execute([$email,$id]);
    }

    public function metadataGet(string $scope, string $scopeId, string $key, mixed $default = null): mixed
    {
        $stmt = $this->pdo->prepare('SELECT v FROM metadata WHERE scope=? AND scope_id=? AND k=?'); $stmt->execute([$scope,$scopeId,$key]);
        $value = $stmt->fetchColumn(); return $value === false ? $default : json_decode((string)$value, true, 512, JSON_THROW_ON_ERROR);
    }

    public function metadataSet(string $scope, string $scopeId, string $key, mixed $value): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR);
        $this->pdo->prepare('INSERT INTO metadata(scope,scope_id,k,v) VALUES(?,?,?,?) ON CONFLICT(scope,scope_id,k) DO UPDATE SET v=excluded.v')->execute([$scope,$scopeId,$key,$json]);
    }

    public function metadataAll(string $scope, string $scopeId): array
    {
        $stmt = $this->pdo->prepare('SELECT k,v FROM metadata WHERE scope=? AND scope_id=? ORDER BY k'); $stmt->execute([$scope,$scopeId]);
        $out = []; while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $out[$row['k']] = json_decode($row['v'], true, 512, JSON_THROW_ON_ERROR); }
        return $out;
    }

    public function recordSent(Email $mail, string $folder, string $recipientEmail): void
    {
        if ($mail->messageId() === null || $mail->id() === null) { return; }
        $stmt = $this->pdo->prepare('INSERT INTO sent_evidence(message_id,recipient_email,folder,server_id,sent_at) VALUES(?,?,?,?,?) ON CONFLICT(message_id) DO UPDATE SET recipient_email=excluded.recipient_email,folder=excluded.folder,server_id=excluded.server_id,sent_at=excluded.sent_at');
        $stmt->execute([$mail->messageId(),$this->normalizeEmail($recipientEmail),$folder,$mail->id(),$mail->date()?->format(DATE_ATOM)]);
    }

    public function sentEvidence(string $messageId): ?MatchedOutgoing
    {
        $stmt = $this->pdo->prepare('SELECT message_id,recipient_email,folder,server_id FROM sent_evidence WHERE message_id=?'); $stmt->execute([$messageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC); return $row === false ? null : new MatchedOutgoing($row['message_id'],$row['recipient_email'],$row['folder'],$row['server_id']);
    }

    public function recordHistory(?string $contactId, Email $mail, string $direction, string $folder): void
    {
        $this->pdo->prepare('INSERT INTO mail_history(contact_id,message_id,direction,folder,server_id,observed_at) VALUES(?,?,?,?,?,?)')->execute([$contactId,$mail->messageId(),$direction,$folder,$mail->id(),(new \DateTimeImmutable())->format(DATE_ATOM)]);
    }

    public function historyForContact(string $contactId): array
    {
        $stmt = $this->pdo->prepare('SELECT message_id,direction,folder,server_id,observed_at FROM mail_history WHERE contact_id=? ORDER BY id'); $stmt->execute([$contactId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
