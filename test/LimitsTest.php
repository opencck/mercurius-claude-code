<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode\Test;

use Amp\PHPUnit\AsyncTestCase;
use Mercurius\ClaudeCode\Limits;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;

/** Calls that wait for the state of the plan share one question to the CLI. */
final class LimitsTest extends AsyncTestCase {
    public function testCallsWaitingForTheStateShareOneQuestion(): void {
        $asked = 0;
        $limits = new Limits(static function () use (&$asked): array {
            $asked++;
            delay(0.2);
            return [['limits' => [['kind' => 'session', 'percent' => 10]], 'extra_usage' => ['is_enabled' => false]], ''];
        });

        await([
            async(static fn() => $limits->admit(null, Limits::STOP, 100.0)),
            async(static fn() => $limits->admit(null, Limits::STOP, 100.0)),
            async(static fn() => $limits->admit(null, Limits::STOP, 100.0)),
        ]);

        self::assertSame(1, $asked);
    }
}
