<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Audit;

/**
 * Tamper-evident hash chain for the append-only audit log. Each entry
 * commits to the previous entry's hash, so altering or deleting any record
 * breaks every link after it.
 *
 * Pure and storage-agnostic: the {@see AuditSink} supplies the previous hash and
 * persists the (prev_hash, canonical_json, entry_hash) triple; this class only
 * computes and verifies. Verification re-hashes the bytes that were stored, so it
 * does not depend on JSON key order being reproducible across processes.
 */
final class HashChain
{
    /** First link's predecessor. 64 hex zeros = "no prior entry". */
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** entry_hash = sha256(prev_hash . "\n" . canonical_json). */
    public static function entryHash(string $prevHash, string $canonicalJson): string
    {
        return hash('sha256', $prevHash . "\n" . $canonicalJson);
    }

    /**
     * Verify an ordered list of entries forms an unbroken chain from GENESIS.
     *
     * @param list<array{prev_hash: string, canonical_json: string, entry_hash: string}> $entries
     */
    public static function verify(array $entries): bool
    {
        $prev = self::GENESIS;
        foreach ($entries as $entry) {
            if ($entry['prev_hash'] !== $prev) {
                return false; // a record was deleted or reordered
            }
            if (self::entryHash($entry['prev_hash'], $entry['canonical_json']) !== $entry['entry_hash']) {
                return false; // a record's contents were altered
            }
            $prev = $entry['entry_hash'];
        }

        return true;
    }
}
