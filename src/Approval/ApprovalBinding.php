<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Approval;

/**
 * Binds an approval to one exact action: an approval token minted for
 * `orders-update {id:1,status:completed}` can NOT be replayed on a different verb
 * or different args. The binding is a deterministic hash of the verb plus the
 * canonicalised call arguments.
 *
 * Pure (no clock/RNG/WP) so the rule is unit-testable and identical at both ends:
 * the gate computes it when it persists the pending request, and again when it
 * validates the token on the agent's retry — they must match.
 *
 * The `_approval` key (the token the agent threads back in) is excluded, so the
 * pre-approval call and the post-approval retry hash to the same value.
 */
final class ApprovalBinding
{
    /** Args key carrying the approval token on the retry; never part of the binding. */
    public const TOKEN_ARG = '_approval';

    /**
     * @param array<string, mixed> $args
     */
    public static function hash(string $verb, array $args): string
    {
        $canonical = self::canonicalize(self::stripToken($args));

        return hash('sha256', $verb . "\n" . (string) json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private static function stripToken(array $args): array
    {
        unset($args[self::TOKEN_ARG]);

        return $args;
    }

    /**
     * Recursively sort associative arrays by key so that logically-equal argument
     * sets hash identically regardless of the order the agent serialised them in.
     * List arrays (sequential 0..n keys) keep their order — position is meaningful.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $sorted = array_map([self::class, 'canonicalize'], $value);

        if (!array_is_list($sorted)) {
            ksort($sorted);
        }

        return $sorted;
    }
}
