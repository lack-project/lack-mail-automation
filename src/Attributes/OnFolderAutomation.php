<?php
declare(strict_types=1);
namespace Lack\MailAutomation\Attributes;

use Attribute;
use Lack\MailAutomation\Folder;

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class OnFolderAutomation
{
    public function __construct(
        public Folder|string $folder,
        public int $priority = 0,
        public ?string $automationId = null,
        public bool $active = true,
        public ?string $flag = null,
    ) {}
}
