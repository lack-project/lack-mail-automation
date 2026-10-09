<?php

declare(strict_types=1);

namespace Lack\MailAutomation\Cli;

use Lack\MailAutomation\MailAutomation;
use Phore\Cli\Annotation\CliParameter;
use Phore\Cli\Annotation\CliScope;
use Phore\Cli\Output\Out;

#[CliScope('mail-automation')]
final class MailAutomationCommand
{
    /**
     * Run the configured mail automation.
     *
     * The bootstrap must return one fully configured MailAutomation instance.
     * Passing a mail ID reprocesses exactly that message without advancing a
     * folder cursor.
     *
     * @param bool $dryRun Analyze/select without persistent state or mail actions.
     * @param string|null $mailId Optional phore/mail-client message reference.
     * @param string $bootstrap Bootstrap file returning MailAutomation.
     *
     * @example vendor/bin/lack-mail-automation run --dry-run
     * @example vendor/bin/lack-mail-automation run --mail-id '<id>'
     * @see MailAutomation::run()
     * @see MailAutomation::runMail()
     */
    public function run(
        #[CliParameter('dry-run', 'Analyze without persistent side effects')]
        bool $dryRun = false,
        #[CliParameter('mail-id', 'Reprocess exactly one mail-client message ID')]
        ?string $mailId = null,
        #[CliParameter('bootstrap', 'Bootstrap file returning MailAutomation')]
        string $bootstrap = 'automail/bootstrap.php',
    ): void {
        $automation = $this->loadBootstrap($bootstrap);
        $report = $mailId === null
            ? $automation->run(dryRun: $dryRun)
            : $automation->runMail($mailId, dryRun: $dryRun);

        $message = sprintf(
            'processed=%d skipped=%d sent=%d errors=%d',
            $report->processed,
            $report->skipped,
            $report->indexedSent,
            count($report->errors()),
        );

        if (!$report->successful()) {
            throw new \RuntimeException('Mail automation failed: ' . $message);
        }

        Out::TextSuccess('Mail automation finished: ' . $message);
    }

    /**
     * Load the application bootstrap and validate its contract.
     *
     * @throws \RuntimeException When the file is missing, unreadable or returns
     *     anything other than MailAutomation.
     * @return MailAutomation Fully configured application instance.
     *
     * @example $automation = $this->loadBootstrap('automail/bootstrap.php');
     * @see MailAutomation::fromConfig()
     */
    private function loadBootstrap(string $path): MailAutomation
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(
                'Mail automation bootstrap must be a readable file: ' . $path,
            );
        }

        $automation = require $path;
        if (!$automation instanceof MailAutomation) {
            throw new \RuntimeException(
                'Mail automation bootstrap must return ' . MailAutomation::class . ': ' . $path,
            );
        }

        return $automation;
    }
}
