<?php
use Lack\MailAutomation\SqliteStorage;

// Independent maintenance operation using the same PDO connection as the automation.
$storage = new SqliteStorage($database);
$contact = $storage->contacts()->findByEmail('anna@old.example');
if ($contact === null) {
    throw new RuntimeException('Contact not found.');
}

$contact->setName('Anna Schneider');
$contact->metadata->set('customerNumber', 'C-1042');
$contact->metadata->set('classification', 'b2b');

$alias = $contact->addAlias('anna@firma.example', name: 'Anna geschäftlich');
$contact->setAliasName($alias->email, 'Anna Schneider – Einkauf');
$contact->setPrimaryEmail($alias->email);
$contact->removeAlias('anna@old.example');

$updated = $storage->contacts()->findById($contact->id);
// Result: same contact ID, anna@firma.example is primary, metadata is persisted,
// and anna@old.example is no longer an active alias.
