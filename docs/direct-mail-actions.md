# Mail automation application flow

## 1. Bootstrap one MailAutomation

```php
<?php

use Lack\MailAutomation\MailAutomation;
use Lack\MailAutomation\MailAutomationConfig;

return MailAutomation::fromConfig(
    new MailAutomationConfig(
        mailbox: dirname(__DIR__) . '/mailclient.yaml',
        actionsDirectory: __DIR__ . '/actions',
    ),
);
```

No connection, lock, cursor, folder-sync or status-flag code belongs in this
application file.

## 2. Run it

```bash
vendor/bin/lack-mail-automation.php run
```

Use `--dry-run` for analysis without persistence or delivery. Use
`--mail-id '<id>'` to retry exactly one message.

## 3. Add actions by adding files

Every concrete `*Action.php` class below `actionsDirectory` carrying
`#[OnMailAction(...)]` is discovered automatically. Application code does not
maintain a parallel registry.

Mailbox Sent/Inbox folder names and automation keywords are configured in the
mailbox configuration. The library performs the synchronization and flag
handling.
