<?php
declare(strict_types=1);
namespace Lack\MailAutomation;

use Phore\MailClient\MailboxFolder;
use Phore\MailClient\MailClient;

enum Folder: string
{
    case Inbox = 'inbox';
    case Sent = 'sent';
    case Drafts = 'drafts';
    case Trash = 'trash';
    case Junk = 'junk';

    public function resolve(MailClient $client): string
    {
        return $client->folder(match ($this) {
            self::Inbox => MailboxFolder::Inbox,
            self::Sent => MailboxFolder::Sent,
            self::Drafts => MailboxFolder::Drafts,
            self::Trash => MailboxFolder::Trash,
            self::Junk => MailboxFolder::Junk,
        });
    }
}
