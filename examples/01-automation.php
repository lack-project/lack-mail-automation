<?php

declare(strict_types=1);

use Lack\MailAutomation\Analysis\MailAnalyzer;
use Lack\MailAutomation\Folder;
use Lack\MailAutomation\MailAutomation;
use Phore\MailClient\MailboxConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

$client = MailboxConfig::fromFile($argv[1])->connect();

$automation = new MailAutomation(
    client: $client,
    storage: $argv[2],
    inboxFolder: Folder::Inbox,
    sentFolder: Folder::Sent,
    mailAnalyzer: new MailAnalyzer(),
);

$automation->scanAutomations(
    __DIR__ . '/actions',
    '*Action.php',
);

$report = $automation->run();

if (!$report->successful()) {
    throw new RuntimeException('Mail automation run failed.');
}
