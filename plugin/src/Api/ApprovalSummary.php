<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Api;

use Specflux\AgentSafety\Plugin\Support\SummaryMarkup;

/**
 * A read-only view of one approval row, shaped for PROGRAMMATIC consumers
 * (the wp-admin page renders raw rows itself; this is the API surface).
 * Carries exactly what an external consumer needs to render an approval
 * prompt and poll for its resolution — never the args themselves (only their
 * hash exists here) and never a bearer token.
 */
final class ApprovalSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $verb,
        /** One of: pending | approved | rejected | expired | in_flight | consumed. */
        public readonly string $status,
        public readonly string $summary,
        public readonly string $correlationId,
        public readonly ?string $createdAtUtc,
        public readonly ?string $pendingExpiresAtUtc,
        /**
         * The pre-approval grant that auto-approved this row (AS-12), or null
         * for an ordinary human click. Its presence is what lets a consumer
         * render "auto-approved under plan grant" rather than implying a human
         * reviewed this exact action.
         */
        public readonly ?string $grantId = null,
    ) {
    }

    /**
     * Map one raw store row ({@see \Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore::get()})
     * into this DTO; unknown/absent columns degrade to empty strings rather
     * than fataling, so a schema drift shows up as an odd-looking summary
     * instead of a 500.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $str = static fn (mixed $v): string => is_string($v) ? $v : '';

        return new self(
            id: $str($row['approval_id'] ?? null),
            verb: $str($row['verb'] ?? null),
            status: $str($row['status'] ?? null),
            // The stored value may carry the host-authored provenance tag
            // ({@see SummaryMarkup}); that tag is a rendering concern of the
            // approval screen and never part of this API surface.
            summary: SummaryMarkup::unwrap($str($row['summary'] ?? null)),
            correlationId: $str($row['correlation_id'] ?? null),
            createdAtUtc: isset($row['created_ts']) && is_string($row['created_ts']) ? $row['created_ts'] : null,
            pendingExpiresAtUtc: isset($row['pending_expires_ts']) && is_string($row['pending_expires_ts']) ? $row['pending_expires_ts'] : null,
            grantId: isset($row['grant_id']) && is_string($row['grant_id']) && $row['grant_id'] !== '' ? $row['grant_id'] : null,
        );
    }
}
