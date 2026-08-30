<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Hooks;

use Specflux\AgentSafety\Approval\ApprovalStore;
use Specflux\AgentSafety\Plugin\Support\ExecutionResult;
use Specflux\AgentSafety\Plugin\Verdict\GrantGate;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Verdict\Hints;
use Specflux\AgentSafety\Plugin\Verdict\VerdictMode;
use Specflux\AgentSafety\Plugin\Verdict\VerdictPipeline;

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
 * This is the CLAIM-mode adapter of the shared {@see VerdictPipeline}: the
 * pipeline decides and reserves; this hook only wraps the callback, feeds the
 * ability's self-reported annotations in as {@see Hints}, translates the
 * {@see \Specflux\AgentSafety\Plugin\Verdict\Verdict}, and owns what happens to a
 * reserved grant AFTER the verdict — "consume on execution success":
 *   - First touch of an irreversible verb with no grant → WP_Error('approval_required')
 *     plus a persisted pending request for a human to review.
 *   - On the retry the pipeline ATOMICALLY RESERVES the human's grant (approved →
 *     in_flight) — by bearer token if presented, else by-reference (the same
 *     authenticated key simply re-issues the identical call, no out-of-band token).
 *   - The reservation is only spent (in_flight → consumed) once the action actually
 *     executed ({@see onExecuted}); a failed/aborted execution rolls it back to
 *     approved so a retry within the TTL can reuse it.
 */
final class AbilityPermissionGate
{
    /** @var array<string, list<string>> verb => stack of reserved approval ids awaiting finalize. */
    private array $reserved = [];

    /**
     * approval id => the pre-approval grant (AS-12) whose reservation minted it.
     *
     * A grant's count is decremented when the reservation is spent and must be
     * given back on EVERY path where the approval is rolled back, or a human's
     * budget is charged for an action that never ran. In-memory and
     * request-scoped, exactly like $reserved: the mint, the reserve and the
     * finalize/rollback all happen inside one request. A process that dies
     * between them leaks the count until the grant's TTL — which fails closed
     * (fewer calls authorised), the safe direction.
     *
     * @var array<string, string>
     */
    private array $grantByApproval = [];

    /**
     * @param list<string> $governedNamespaces Ability-id prefixes this gate governs
     *                                          (contributed by integrations + the
     *                                          `agent_safety_governed_namespaces`
     *                                          filter). Empty => inert no-op, same as
     *                                          today on a site with no integration active.
     */
    public function __construct(
        private readonly VerdictPipeline $pipeline,
        private readonly PackResolver $packs,
        private readonly ?ApprovalStore $approvals = null,
        private readonly array $governedNamespaces = [],
        private readonly ?GrantGate $grants = null,
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
            //
            // `wp_before_execute_ability` is the SAME sweep applied one call
            // earlier. Core's WP_Ability::execute() returns early when the
            // execute callback hands back a WP_Error, so `wp_after_execute_ability`
            // never fires for a refused write and onExecuted() never sees it.
            // With only the shutdown sweep, a reservation for a refused action
            // was held for the rest of the request — and one request here can
            // carry a whole agent loop, so an agent retrying a write its own
            // validator refused burned a human's pre-approval on writes that
            // never happened, then parked for an approval already given.
            add_action('wp_before_execute_ability', [$this, 'onBeforeExecute'], 10, 2);
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
        // The registration's meta.annotations are static per ability, so the
        // hints can be read once here rather than on every permission check.
        $hints = Hints::fromAbilityArgs($args);
        $pipeline = $this->pipeline;
        $packs = $this->packs;
        $self = $this;

        $args['permission_callback'] = static function ($input = null) use ($original, $name, $hints, $pipeline, $packs, $self) {
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

            $verdict = $pipeline->judge($name, is_array($input) ? $input : [], $pack, $hints, VerdictMode::Claim);
            if ($verdict->reservedApprovalId !== null) {
                $self->remember($name, $verdict->reservedApprovalId, $verdict->grantId);
            }

            return $verdict->error() ?? true;
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
     * Record a reservation so {@see onExecuted} can finalize it after the action
     * runs — and, when a pre-approval grant paid for it, which grant to give the
     * count back to if it does not.
     */
    private function remember(string $verb, string $approvalId, ?string $grantId = null): void
    {
        $this->reserved[$verb][] = $approvalId;

        if ($grantId !== null && $grantId !== '') {
            $this->grantByApproval[$approvalId] = $grantId;
        }
    }

    /**
     * Release the approval AND, when a grant paid for it, that grant's
     * reservation. "Consume on execution success" applies to both: a grant's
     * count is sealed by finalize and restored by rollback, never spent on an
     * action that did not happen.
     */
    private function rollback(string $approvalId): void
    {
        $this->approvals?->rollback($approvalId);

        $grantId = $this->grantByApproval[$approvalId] ?? null;
        if ($grantId !== null) {
            unset($this->grantByApproval[$approvalId]);
            $this->grants?->release($grantId);
        }
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
            // Sealed: the irreversible action truly ran, so the grant's
            // decremented count stands.
            $this->approvals->finalize($approvalId);
            unset($this->grantByApproval[$approvalId]);
        } else {
            $this->rollback($approvalId);
        }
    }

    /**
     * A NEW call of this ability is about to execute, so any reservation still
     * held from an EARLIER call of it never reached onExecuted — exactly the
     * condition {@see onShutdown} sweeps, just recognised at the next call
     * boundary instead of at the end of the request. Release those, and keep
     * only the reservation belonging to the call now starting.
     *
     * The current call's approval id may sit on the stack more than once: a
     * caller that checks permissions itself and then calls execute() runs the
     * permission callback twice, and the second run re-claims the SAME approval
     * row. So the rule is "everything that is not the current approval id",
     * not "everything but the last entry".
     *
     * Idempotent by construction: {@see rollback()} forgets the grant behind an
     * approval as it releases it, so a duplicate entry swept later credits
     * nothing a second time.
     *
     * @param mixed $input
     */
    public function onBeforeExecute(string $name, $input = null): void
    {
        unset($input);

        if ($this->approvals === null || empty($this->reserved[$name])) {
            return;
        }

        $current = end($this->reserved[$name]);
        $keep = [];
        $stale = [];
        foreach ($this->reserved[$name] as $approvalId) {
            if ($approvalId === $current) {
                $keep[] = $approvalId;
                continue;
            }
            $stale[] = $approvalId;
        }

        $this->reserved[$name] = $keep;
        foreach ($stale as $approvalId) {
            $this->rollback($approvalId);
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
                $this->rollback($approvalId);
            }
        }
        $this->reserved = [];
        $this->grantByApproval = [];
    }
}
