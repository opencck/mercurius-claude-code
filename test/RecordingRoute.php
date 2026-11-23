<?php

declare(strict_types=1);

namespace Mercurius\ClaudeCode\Test;

use Amp\Cancellation;
use Amp\Socket\ConnectContext;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\SocketConnector;

use function Amp\Socket\connect;

/** A route for tests: leads every connection to one local address and remembers where it was asked to go. */
final class RecordingRoute implements SocketConnector {
    /** @var list<string> */
    public array $asked = [];

    public function __construct(private readonly string $target) {}

    public function connect(SocketAddress|string $uri, ?ConnectContext $context = null, ?Cancellation $cancellation = null): Socket {
        $this->asked[] = (string) $uri;
        return connect($this->target, $context, $cancellation);
    }
}
