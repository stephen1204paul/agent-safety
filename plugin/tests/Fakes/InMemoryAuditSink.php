<?php

declare(strict_types=1);

namespace Specflux\WooAgentSafety\Plugin\Tests\Fakes;

use Specflux\WooAgentSafety\Audit\AuditRecord;
use Specflux\WooAgentSafety\Audit\AuditSink;

/**
 * Hand-rolled fake sink (house style: no mocking framework, see
 * tests/AuditTest.php in the core suite). Just captures every appended record
 * for assertions.
 */
final class InMemoryAuditSink implements AuditSink
{
    /** @var list<AuditRecord> */
    public array $records = [];

    public function append(AuditRecord $record): void
    {
        $this->records[] = $record;
    }
}
