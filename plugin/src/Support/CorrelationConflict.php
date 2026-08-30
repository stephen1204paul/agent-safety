<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use RuntimeException;

/**
 * Thrown by {@see RequestContext::withCorrelation()} when a DIFFERENT
 * correlation id has already been memoized for this PHP process.
 *
 * This is a throw, not a notice, on purpose. Once grants exist (AS-12) the
 * correlation id is a SECURITY KEY, not a log tag: it is half of what a grant
 * matches on. Something has already read `correlation()` and pinned a
 * different scope, so continuing would either run the host's work under the
 * wrong scope — and against the wrong run's grants — or silently split one
 * run's audit trail across two ids. Both are worse than failing the call, so
 * the host is expected to let this abort whatever it was about to do.
 */
final class CorrelationConflict extends RuntimeException
{
}
