<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Admin;

/**
 * §3.6.6: the core library's reason codes (`unknown_verb`, `state_unverifiable`,
 * `approval_required`, `rate_limited_*`, ...) are plain machine strings —
 * WordPress-free, so untranslated by construction. This is the one place the
 * plugin maps them to a translated label for a human reading an admin screen
 * (the audit log, the pending-actions queue). Each case passes a LITERAL
 * string to `__()` (a variable there is a Plugin Check error), so an unknown
 * or future code falls through to the raw code rather than being silently
 * mistranslated.
 */
final class ReasonLabels
{
    public static function label(string $reason): string
    {
        switch ($reason) {
            case 'unknown_verb':
                return __('Unknown verb', 'agent-tollgate');
            case 'readonly_but_writes':
                return __('Read-only pack attempted a write', 'agent-tollgate');
            case 'not_in_pack':
                return __('Not included in the bound pack', 'agent-tollgate');
            case 'denied_by_class':
                return __('Denied by tier class', 'agent-tollgate');
            case 'denied_by_class_destructive_hint':
                return __('Denied — destructive hint on a denied tier class', 'agent-tollgate');
            case 'state_unverifiable':
                return __('Target state could not be verified', 'agent-tollgate');
            case 'approval_required':
                return __('Approval required', 'agent-tollgate');
            case 'site_paused':
                return __('Site paused (emergency stop)', 'agent-tollgate');
            case 'denial_lockout':
                return __('Denial lockout', 'agent-tollgate');
            case 'repeat_call':
                return __('Repeated identical call', 'agent-tollgate');
            case 'calls_per_minute':
                return __('Rate limit: calls per minute', 'agent-tollgate');
            case 'calls_per_hour':
                return __('Rate limit: calls per hour', 'agent-tollgate');
            case 'forbidden_argument':
                return __('Forbidden argument', 'agent-tollgate');
            case 'not_allowed_value':
                return __('Argument value not allowed', 'agent-tollgate');
            case 'unreadable_argument':
                return __('Argument unreadable', 'agent-tollgate');
            case 'max_items_per_call':
                return __('Too many items in one call', 'agent-tollgate');
            case 'max_per_call':
                return __('Argument cap exceeded (per call)', 'agent-tollgate');
            case 'max_total_per_day':
                return __('Argument cap exceeded (per day)', 'agent-tollgate');
            case 'stale':
                return __('Stale — the target changed before this could be claimed', 'agent-tollgate');
            case 'void_environment':
                return __('Voided — the site address changed', 'agent-tollgate');
        }

        if (str_starts_with($reason, 'rate_limited_')) {
            return sprintf(
                /* translators: %s the specific rate-limit rule that tripped */
                __('Rate limited (%s)', 'agent-tollgate'),
                substr($reason, strlen('rate_limited_'))
            );
        }

        if (str_starts_with($reason, 'argument_cap_')) {
            return sprintf(
                /* translators: %s the specific argument cap that tripped */
                __('Argument cap (%s)', 'agent-tollgate'),
                substr($reason, strlen('argument_cap_'))
            );
        }

        // Unmapped: a code this map doesn't (yet) know, or a decision/audit
        // outcome rather than a gate reason. Shown as-is rather than guessed at.
        return $reason;
    }
}
