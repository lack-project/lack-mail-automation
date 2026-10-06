#!/usr/bin/env php
<?php

declare(strict_types=1);

use Lack\MailAutomation\Cli\MailAutomationCommand;
use Phore\Cli\CliApplication;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new CliApplication();
$app->addClass(MailAutomationCommand::class);

$arguments = [$argv[0], 'mail-automation', ...array_slice($argv, 1)];
$app->run($arguments);
