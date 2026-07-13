<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Hooks;

use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Approval\ApprovalStore;
use Specflux\AgentSafety\Gate\Decision;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Gate\GateContext;
use Specflux\AgentSafety\Gate\Outcome;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\ExecutionResult;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RateLimitGate;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use WP_Error;

/**
 * Working gate seam for the SHIPPING stack (verified: WooCommerce 10.8.1 vendors
 * mcp-adapter v0.1.0, which lacks the `mcp_adapter_pre_tool_call` filter — that
 * arrived in v0.5.0). This seam instead lives in WP 7.0 core's Abilities API,
 * so it is independent of the adapter version:
 *
 *   - `wp_register_ability_args` lets us wrap ANY ability's `permission_callback`
 *     at registration (incl. WooCommerce's, which we did not register).
 *   - The adapter's ToolsHandler calls `$ability->check_permissions($args)` before
 *     executing; a returned WP_Error becomes the tool-call denial (code + message).
 *
 * On this seam the ability NAME is already our canonical verb id
 * ("woocommerce/orders-update"), so no tool-name mapping is needed.
 *
 * Approval flow is async and "consume on execution success":
 *   - First touch of an irreversible verb with no grant → WP_Error('approval_required')
 *     plus a persisted pending request for a human to review.
 *   - On the retry the gate ATOMICALLY RESERVES the human's grant (approved →
 *     in_flight) — by bearer token if presented, else by-reference (the same
 *     authenticated key simply re-issues the identical call, no out-of-band token).
 *   - The reservation is only spent (in_flight → consumed) once the action actually
 *     executed ({@see onExecuted}); a failed/aborted execution rolls it back to
 *     approved so a retry within the TTL can reuse it.
 */
final class AbilityPermissionGate
{
    /** @var array<string, true> verb|args_hash already claimed in THIS request (re-entrancy guard). */
    private array $reentry = [];

    /** @var array<string, list<string>> verb => stack of reserved approval ids awaiting finalize. */
    private array $reserved = [];

    /**
     * @param list<string> $governedNamespaces Ability-id prefixes this gate governs
     *                                          (contributed by integrations + the
     *                                          `agent_safety_governed_namespaces`
     *                                          filter). Empty => inert no-op, same as
     *                                          today on a site with no integration active.
     */
    public function __construct(
        private readonly Gate $gate,
        private readonly PackResolver $packs,
        private readonly DecisionRecorder $recorder,
        private readonly ?ApprovalStore $approvals = null,
        private readonly array $governedNamespaces = [],
        private readonly RateLimitGate $rateLimits = new RateLimitGate(),
    ) {
    }

    public function register(): void
    {
        // Must be added before WooCommerce registers its abilities. Registering at
        // plugin-load time guarantees this (our plugin loads before woocommerce).
        add_filter('wp_register_ability_args', [$this, 'wrap'], 10, 2);

        if ($this->approvals !== null) {
            // Spend a reservation only when the action truly executed: finalize on
            // success, roll back on failure, and release anything still reserved at
            // shutdown (a fatal / abort before execution).
            add_action('wp_after_execute_ability', [$this, 'onExecuted'], 10, 3);
            add_action('shutdown', [$this, 'onShutdown'], 0);
        }
    }

    /**
     * @param array<string, mixed> $args The ability registration args.
     * @return array<string, mixed>
     */
    public function wrap(array $args, string $name): array
    {
        // Only govern the namespaces integrations have registered.
        // Pass everything else (core/*, other plugins, or ALL abilities on a site
        // with no integration active) through untouched so we never break them.
        if (!$this->isGoverned($name)) {
            return $args;
        }

        $original = $args['permission_callback'] ?? null;
        $gate = $this->gate;
        $packs = $this->packs;
        $self = $this;

        $args['permission_callback'] = static function ($input = null) use ($original, $name, $gate, $packs, $self) {
            // Preserve the ability's own capability check first (least privilege).
            if (is_callable($original)) {
                $orig = $original($input);
                if (true !== $orig) {
                    return $orig; // original denial / WP_Error wins
                }
            }

            // Resolve the pack AT CALL TIME, never at registration time: wrap()
            // runs on `init` (mcp-adapter registers abilities at init/20), but
            // application-password identity only exists once the REST server's
            // authentication phase has run — strictly after init. A pack captured
            // at registration time would therefore ALWAYS be the default pack for
            // app-password-authenticated agents, silently ignoring every binding
            // an administrator configured (found by live smoke test, 2026-07-07).
            $pack = $packs->resolve();

            $callArgs = is_array($input) ? $input : [];

            // Evaluate WITHOUT an approval first: reservation is a side effect, and it
            // must only fire when approval is the ONLY thing blocking the call (all
            // other deny gates — unknown verb, not-in-pack, class-denied — passed).
            $decision = $gate->evaluate(new GateContext(
                verb: $name,
                args: $callArgs,
                pack: $pack,
                selfReportedReadonly: false,
                hasValidApproval: false,
            ));

            // Approval is the sole blocker: try to claim a human grant for this exact
            // action. If we get one, re-evaluate as approved → Allow.
            if (Outcome::ApprovalRequired === $decision->outcome && $self->claimApproval($name, $callArgs)) {
                $decision = $gate->evaluate(new GateContext(
                    verb: $name,
                    args: $callArgs,
                    pack: $pack,
                    selfReportedReadonly: false,
                    hasValidApproval: true,
                ));
            }

            // Rate/quota caps (backlog #16) apply only to a call that would otherwise
            // proceed — a denial must never itself consume quota. If a claimed
            // approval grant is downgraded to Deny here, it is left `in_flight`;
            // onShutdown()'s existing safety net releases it back to `approved`
            // since the action never reaches onExecuted(), so a retry under the
            // cap can still reuse the same human grant.
            if (Outcome::Allow === $decision->outcome) {
                $decision = $self->enforceRateLimit($pack, $decision, $name, $callArgs);
            }

            // For calls that do NOT execute (denied / approval-pending): persist a
            // pending approval (when required) and audit the verdict. Allowed calls
            // are audited at execution time by AbilityAuditLog.
            $approvalId = null;
            if (Outcome::Allow !== $decision->outcome) {
                $eventId = RequestContext::event();
                if (Outcome::ApprovalRequired === $decision->outcome) {
                    $approvalId = $self->requestApproval($name, $callArgs, $eventId);
                }
                $self->audit($eventId, $name, $callArgs, $pack, $decision, $approvalId);
            }

            return match ($decision->outcome) {
                Outcome::Allow => true,
                Outcome::Deny => new WP_Error(
                    'agent_safety_denied',
                    sprintf('Blocked by Agent Safety (%s): %s', $pack->name, $decision->reason),
                    ['status' => 403, 'verb' => $name, 'tier' => $decision->tier?->value]
                ),
                Outcome::ApprovalRequired => new WP_Error(
                    'approval_required',
                    sprintf('"%s" is irreversible and requires human approval before it can run. A request has been logged for review.', $name),
                    array_filter([
                        'status' => 202,
                        'verb' => $name,
                        'tier' => $decision->tier?->value,
                        'approval_id' => $approvalId,
                    ], static fn ($v) => $v !== null)
                ),
            };
        };

        return $args;
    }

    /** Is this ability name under one of the governed namespace prefixes? */
    private function isGoverned(string $name): bool
    {
        foreach ($this->governedNamespaces as $namespace) {
            if ($namespace !== '' && str_starts_with($name, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Emit a gate-decision audit record for a non-executing verdict —
     * a denial or a pending approval. The caller supplies the event id so the same
     * id can link the pending approval row. No-op when no sink is wired. Public so
     * the permission-callback closure ($self) can reach it.
     *
     * @param array<string, mixed> $input
     */
    public function audit(string $eventId, string $name, array $input, Pack $pack, Decision $decision, ?string $approvalId = null): void
    {
        $this->recorder->auditDecision($eventId, $name, $input, $pack, $decision, $approvalId);
    }

    /**
     * Enforce this pack's rate/quota caps (backlog #16) on a decision that is
     * otherwise Allow. Denials never reach here, so a blocked call never
     * consumes quota. Returns the SAME decision when admitted (the call has
     * just been counted against the pack's limits as a side effect), or a Deny
     * naming the tripped limit when the cap is exceeded. Public so the
     * permission-callback closure ($self) can reach it.
     */
    /** @param array<string, mixed> $args */
    public function enforceRateLimit(Pack $pack, Decision $decision, string $verb, array $args): Decision
    {
        $tripped = $this->rateLimits->admit($pack, RequestContext::tokenId(), $verb, $args);

        return $tripped === null ? $decision : Decision::deny('rate_limited_' . $tripped, $decision->tier);
    }

    /**
     * Persist (or reuse) a pending approval for an irreversible verb and return its
     * id; the pending audit row's event id is linked for cross-reference, and the
     * requesting principal (identity-provider token id) is bound so a by-reference
     * retry can match it.
     * No-op (returns null) when no approval store is wired.
     *
     * @param array<string, mixed> $input
     */
    public function requestApproval(string $name, array $input, string $auditEventId): ?string
    {
        if ($this->approvals === null) {
            return null;
        }

        return $this->recorder->requestApproval($name, $input, $auditEventId);
    }

    /**
     * Atomically claim a human grant for this exact verb+args — by bearer
     * token if the agent threaded one back, else by-reference for the requesting key.
     * Records the reservation so {@see onExecuted} can finalize it after the action
     * runs. Public so the permission-callback closure ($self) can reach it.
     *
     * Woo runs an allowed ability through rest_do_request, which RE-ENTERS this
     * permission check within the SAME request. The grant is now `in_flight`, so a
     * second reserve would find nothing and deny the very action it just allowed.
     * Memoize the first claim per (verb, args_hash) so re-entrant checks pass without
     * a second reservation. Cross-request single-claim is still enforced by the DB.
     *
     * @param array<string, mixed> $input
     */
    public function claimApproval(string $verb, array $input): bool
    {
        if ($this->approvals === null) {
            return false;
        }

        $argsHash = ApprovalBinding::hash($verb, $input);
        $memoKey = $verb . '|' . $argsHash;
        if (isset($this->reentry[$memoKey])) {
            return true;
        }

        $token = isset($input[ApprovalBinding::TOKEN_ARG]) ? (string) $input[ApprovalBinding::TOKEN_ARG] : null;
        $approvalId = $this->approvals->reserve($token, $verb, $argsHash, RequestContext::tokenId());
        if ($approvalId === null) {
            return false;
        }

        $this->reentry[$memoKey] = true;
        $this->reserved[$verb][] = $approvalId;

        return true;
    }

    /**
     * After an ability executes, spend or release its reservation. Success →
     * finalize (the irreversible action truly ran). Failure (incl. Woo's
     * rest_do_request error-shaped payloads) → rollback, so a retry within the TTL
     * can reuse the grant. Non-reserved abilities are ignored.
     *
     * @param mixed $input
     * @param mixed $result
     */
    public function onExecuted(string $name, $input, $result): void
    {
        if ($this->approvals === null || empty($this->reserved[$name])) {
            return;
        }

        $approvalId = array_pop($this->reserved[$name]);
        if (empty($this->reserved[$name])) {
            unset($this->reserved[$name]);
        }

        if (ExecutionResult::isSuccess($result)) {
            $this->approvals->finalize($approvalId);
        } else {
            $this->approvals->rollback($approvalId);
        }
    }

    /**
     * Anything still reserved at shutdown never reached onExecuted — the action did
     * not run (a fatal, or an abort before execution). Release the grants so a retry
     * within the TTL can reuse them; an irreversible action is never silently spent.
     */
    public function onShutdown(): void
    {
        if ($this->approvals === null) {
            return;
        }

        foreach ($this->reserved as $ids) {
            foreach ($ids as $approvalId) {
                $this->approvals->rollback($approvalId);
            }
        }
        $this->reserved = [];
    }
}
