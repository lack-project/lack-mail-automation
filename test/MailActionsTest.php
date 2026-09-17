<?php
declare(strict_types=1);
namespace Lack\MailAutomation\Test;

use Lack\MailAutomation\MailAction;
use Lack\MailAutomation\MailActions;
use Lack\MailAutomation\ScheduledMailActions;
use PHPUnit\Framework\TestCase;

final class MailActionsTest extends TestCase
{
    public function testScheduleBuildsActionsFluently(): void
    {
        $action = MailActions::schedule()
            ->addFlag('customer')
            ->moveTo('customers');

        self::assertInstanceOf(MailAction::class, $action);
        self::assertInstanceOf(ScheduledMailActions::class, $action);
        self::assertSame([
            ['type'=>'addFlag','args'=>['customer']],
            ['type'=>'moveTo','args'=>['customers',false]],
        ], $action->items());
    }

    public function testConvenienceFactoriesPrepopulateTheSameScheduleType(): void
    {
        $move = MailActions::moveTo('customers', reprocess:true);
        $raw = MailActions::moveToRaw('Legacy/Exact');

        self::assertInstanceOf(ScheduledMailActions::class, $move);
        self::assertInstanceOf(ScheduledMailActions::class, $raw);
        self::assertSame([['type'=>'moveTo','args'=>['customers',true]]], $move->items());
        self::assertSame([['type'=>'moveToRaw','args'=>['Legacy/Exact',false]]], $raw->items());
    }

    public function testPassAndCompleteUseTheSameResultTypeWithoutScheduledActions(): void
    {
        $pass = MailActions::pass();
        $complete = MailActions::complete();

        self::assertInstanceOf(ScheduledMailActions::class, $pass);
        self::assertInstanceOf(ScheduledMailActions::class, $complete);
        self::assertTrue($pass->isPass());
        self::assertFalse($pass->isComplete());
        self::assertTrue($complete->isComplete());
        self::assertFalse($complete->isPass());
        self::assertSame([], $pass->items());
        self::assertSame([], $complete->items());
    }

    public function testTerminalResultsCannotBeMutated(): void
    {
        $this->expectException(\LogicException::class);
        MailActions::pass()->addFlag('should-not-run');
    }
}
