<?php
declare(strict_types=1);

namespace Lack\MailAutomation\Analysis {
    // Test-only provider seam: never contact a model from this regression suite.
    function phore_ai_choices($prompt, $choices, ...$options): ?array
    {
        \Lack\MailAutomation\Test\AiMailActionsTest::$choices = $choices;
        return \Lack\MailAutomation\Test\AiMailActionsTest::$selection;
    }
}

namespace Lack\MailAutomation\Test {
    use Lack\MailAutomation\Analysis\{AnalyzedMail, ContentAnalysis, MailActionMatcher, MailAnalyzer, MailSummary};
    use Lack\MailAutomation\Attributes\OnMailAction;
    use Lack\MailAutomation\{ContactResolution, ContactResolutionStatus, DraftSender, Folder, MailAction, MailActions, MailAutomation, MailContext, SqliteStorage};
    use Phore\MailClient\{Email, MailClient};
    use PHPUnit\Framework\TestCase;

    require_once __DIR__ . '/MailAutomationTest.php';

    final class AiMailActionsTest extends TestCase
    {
        public static ?array $selection = null;
        public static array $choices = [];

        protected function setUp(): void
        {
            self::$selection = null;
            self::$choices = [];
        }

        private function handler(string $unused = ''): object
        {
            return new class {
                public int $called = 0;
                public int $archiveGuardCalls = 0;
                #[OnMailAction('A new request.', id: 'inbox', folder: Folder::Inbox)]
                public function inbox(AnalyzedMail $mail, MailContext $context): MailAction
                { $this->called++; return MailActions::complete(); }
                #[OnMailAction('An archived request.', id: 'archive', when: 'archiveGuard', folder: 'Archive')]
                public function archive(AnalyzedMail $mail, MailContext $context): MailAction
                { $this->called++; return MailActions::complete(); }
                public function archiveGuard(AnalyzedMail $mail, MailContext $context): bool
                { $this->archiveGuardCalls++; return true; }
            };
        }

        private function context(string $folder = 'INBOX', bool $dryRun = false): array
        {
            $client = new MailClient(new TestSyncTransport(), 'test-account', from: 'me@example.org');
            $storage = new SqliteStorage(new \PDO('sqlite::memory:'));
            $storage->bindAccount($client->accountId());
            $email = (new Email(to: 'me@example.org', subject: 'Request'))->withMarkdown('Body');
            $summary = new MailSummary('target', null, 'Request', ['applicant@example.org'], ['me@example.org'], [], null, new \DateTimeImmutable(), 'incoming', [], null, 'Summary only', null, [], true);
            $mail = new AnalyzedMail($email, new ContentAnalysis('Summary only', 'Body'), $summary, []);
            $context = new MailContext($email, $folder, 'incoming', null, new ContactResolution(ContactResolutionStatus::Unknown), \phore_log(), $dryRun, $storage, $client, $mail);
            return [$mail, $context, $client, $storage];
        }

        public function testFolderFilteringPrecedesGuardsAndDispatch(): void
        {
            [$mail, $context] = $this->context();
            $handler = $this->handler();
            $matcher = new MailActionMatcher($handler);
            self::$selection = ['inbox'];
            self::assertSame('inbox', $matcher->select($mail, $context));
            self::assertSame(['inbox'], array_keys(self::$choices));
            self::assertSame(0, $handler->archiveGuardCalls);
            self::assertSame(0, $handler->called);
            self::assertTrue($matcher($mail->getOriginalMail(), $context)->isComplete());
            self::assertSame(1, $handler->called);
        }

        public function testDryRunAndNoMatchDoNotInvokeHandlersOrWriteActionMetadata(): void
        {
            [$mail, $context] = $this->context(dryRun: true);
            $handler = $this->handler();
            self::$selection = ['inbox'];
            self::assertTrue((new MailActionMatcher($handler))($mail->getOriginalMail(), $context)->isPass());
            self::assertSame(0, $handler->called);
            self::assertNull($context->metadata->get('ai.action'));
            [$mail, $context] = $this->context();
            self::$selection = null;
            self::assertTrue((new MailActionMatcher($handler, flagUnhandled: true))($mail->getOriginalMail(), $context)->isActionRequired());
            self::assertNull($context->metadata->get('ai.action'));
        }

        public function testCannotDispatchAnActionFromAnotherFolder(): void
        {
            [$mail, $context] = $this->context();
            self::$selection = ['archive'];
            $this->expectException(\UnexpectedValueException::class);
            (new MailActionMatcher($this->handler()))->select($mail, $context);
        }

        public function testDirectRegistrationDeduplicatesFoldersAndRejectsDuplicateGroupsAtomically(): void
        {
            [, , $client, $storage] = $this->context();
            $automation = new MailAutomation($client, $storage, mailAnalyzer: new MailAnalyzer());
            $other = new class {
                #[OnMailAction('Another inbox action.', id: 'other', folder: 'INBOX')]
                public function other(AnalyzedMail $mail, MailContext $context): MailAction { return MailActions::complete(); }
            };
            $automation->addMailActions([$this->handler(), $other]);
            $property = new \ReflectionProperty($automation, 'rules');
            self::assertCount(2, $property->getValue($automation));
            try {
                $automation->addMailActions($this->handler());
                self::fail('Duplicate group must fail.');
            } catch (\InvalidArgumentException) {
                self::assertCount(2, $property->getValue($automation));
            }
        }

        public function testDuplicateActionIdsAcrossObjectsAreRejected(): void
        {
            $this->expectException(\InvalidArgumentException::class);
            new MailActionMatcher([$this->handler(), $this->handler()]);
        }

        public function testDirectRegistrationRequiresAnalyzer(): void
        {
            [, , $client, $storage] = $this->context();
            $this->expectException(\LogicException::class);
            (new MailAutomation($client, $storage))->addMailActions($this->handler());
        }

        public function testSendMailUsesExplicitRecipientAndRespectsDryRunAndSender(): void
        {
            foreach (['draft', 'dry-run', 'sender'] as $mode) {
                $transport = new TestSyncTransport();
                $transport->addMessage('INBOX', 1, "From: forwarder@example.org\r\nTo: me@example.org\r\nSubject: Lead\r\nMessage-ID: <lead@example.org>\r\n\r\n");
                $client = new MailClient($transport, 'test-account', from: 'me@example.org');
                $sender = new class implements DraftSender {
                    public ?Email $sent = null;
                    public function send(Email $draft): void { $this->sent = $draft; }
                };
                $automation = new MailAutomation($client, new \PDO('sqlite::memory:'), sender: $mode === 'sender' ? $sender : null);
                $out = (new Email(to: 'applicant@example.org', subject: 'Welcome'))->withMarkdown('Hello');
                $automation->register(Folder::Inbox, static fn() => true, static fn() => MailActions::schedule()->sendMail($out));
                self::assertTrue($automation->run(dryRun: $mode === 'dry-run')->successful());
                if ($mode === 'draft') {
                    self::assertCount(1, $transport->rawMessages['Drafts']);
                    self::assertStringContainsString('applicant@example.org', reset($transport->rawMessages['Drafts']));
                } else {
                    self::assertCount(0, $transport->messages['Drafts']);
                }
                if ($mode === 'sender') {
                    self::assertSame($out, $sender->sent);
                }
                if ($mode === 'dry-run') {
                    self::assertSame([], $transport->messages['INBOX'][1]);
                }
            }
        }
    }
}
