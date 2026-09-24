<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Approval;

use Specflux\AgentSafety\Approval\ApprovalBinding;

/**
 * Turns one {@see StateProbe} result into the sha256 fingerprint stored on
 * an Approval row and compared with {@see hash_equals()} at claim/approve
 * time (AS-6, §3.3 item 1). Reuses {@see ApprovalBinding}'s canonicalisation
 * so a probe result that differs only in key order hashes identically.
 */
final class StateFingerprint
{
    /** @param array<string, mixed> $probeResult */
    public static function compute(array $probeResult): string
    {
        return hash('sha256', (string) json_encode(
            ApprovalBinding::canonicalize($probeResult),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
