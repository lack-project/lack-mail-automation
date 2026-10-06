<?php

declare(strict_types=1);

use Lack\MailAutomation\Analysis\MailAnalyzer;
use Lack\MailAutomation\MailAutomation;
use Phore\MailClient\MailboxConfig;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/actions/InitialContactAction.php';

$client = MailboxConfig::fromFile($argv[1])->connect();

$automation = new MailAutomation(
    client: $client,
    storage: $argv[2],
    mailAnalyzer: new MailAnalyzer(),
);

$automation->addMailAutomation(new InitialContactAction());

// Manual control keeps the same order as run(): Sent before Inbox.
$automation->scanSent();
$automation->scanInbox();
