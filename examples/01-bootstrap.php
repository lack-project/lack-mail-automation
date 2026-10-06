<?php

declare(strict_types=1);

use Lack\MailAutomation\MailAutomation;
use Lack\MailAutomation\MailAutomationConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

return MailAutomation::fromConfig(
    new MailAutomationConfig(
        mailbox: __DIR__ . '/mailbox.yaml',
        storage: __DIR__ . '/run/mail-automation.sqlite',
        actionsDirectory: __DIR__ . '/actions',
    ),
);
