<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Audit;

/**
 * Append-only destination for audit records. The core defines the
 * contract; the host supplies the storage (a DB table, a file, a SIEM stream).
 * Implementations MUST be append-only and SHOULD link each record into the
 * {@see HashChain} so the trail is tamper-evident.
 */
interface AuditSink
{
    public function append(AuditRecord $record): void;
}
