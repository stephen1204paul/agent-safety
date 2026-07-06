<?php

declare(strict_types=1);

namespace Specflux\WooAgentSafety\Plugin\Hooks;

use Specflux\WooAgentSafety\Audit\AuditDecision;
use Specflux\WooAgentSafety\Audit\AuditRecord;
use Specflux\WooAgentSafety\Audit\AuditSink;
use Specflux\WooAgentSafety\Audit\Redactor;
use Specflux\WooAgentSafety\Policy\TierClassifier;
use Specflux\WooAgentSafety\Plugin\Support\PackResolver;
use Specflux\WooAgentSafety\Plugin\Support\RequestContext;
use WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface;

/**
 * Proves a maintainer claim on upstream issue #176: a plugin CAN build a full
 * audit trail for MCP tool calls on mcp-adapter's existing public API alone —
 * `McpObservabilityHandlerInterface` — with NO adapter changes.
 *
 * mcp-adapter's `RequestRouter::route_request()` fires exactly one `mcp.request`
 * event per `tools/call`, synchronously, inside the request, with a `status` of
 * `success` or `error` and (on tool component_type) `tool_name` / `ability_name` /
 * `source` tags (see upstream `McpTool::get_observability_context()`). This
 * handler turns that single event into one {@see AuditRecord} per call:
 *   - status=success                                    -> an execution record.
 *   - status=error, failure_reason === "Permission denied" translated string
 *                                                        -> a Denied decision record.
 *   - status=error, anything else (blocked by a
 *     `mcp_adapter_pre_tool_call` subscriber, a tool execution error, or an
 *     execution exception the adapter already turned into a WP_Error message)
 *                                                        -> an execution record
 *     whose `result` carries the failure. AuditRecord::execution() always
 *     stamps `decision=Allowed` (see its factory) which is the correct read
 *     here: the call passed the adapter's permission check and reached
 *     execution — it just didn't succeed. Using `execution()` (rather than
 *     inventing a new AuditRecord factory) keeps this handler on the existing,
 *     unit-tested AuditRecord API; `result` is a plain string exactly like
 *     {@see AbilityAuditLog}'s 'success'/'failure', just prefixed 'error: '
 *     so it reads distinctly in the admin log and CSV export.
 *
 * DOCUMENTED UPSTREAM GAP (acknowledged by the maintainer): the permission-denied
 * branch is classified by comparing `failure_reason` against the TRANSLATED
 * 'Permission denied' string (mcp-adapter's `ToolsHandler::call_tool()` has no
 * machine-readable outcome / error_code on that branch — only that copy, whether
 * it's mcp-adapter's own default message or a WP_Error's custom message, both of
 * which flow through as free text). This string-compare is inherently fragile:
 * a locale mismatch, or upstream editing that copy, silently breaks the
 * classification (it would then fall through to the generic "some other error"
 * branch above, still audited, just mis-labelled Allowed/execution instead of
 * Denied/decision). Tracked as the open ask in UPSTREAM_ISSUE_audit_hook.md.
 *
 * The OTHER acknowledged gap this handler works around: raw tool arguments never
 * appear in `mcp.request` tags (only redacted `arguments_count` / `arguments_keys`
 * metadata does — see `RequestRouter::sanitize_params_for_logging()`), and the
 * permission check runs BEFORE `mcp_adapter_pre_tool_call`, so a denied call's
 * raw args are never observable at all through the public API. We recover raw
 * args for every OTHER outcome by additionally hooking `mcp_adapter_pre_tool_call`
 * ourselves (still 100% public API) and stashing them for `record_event()` to
 * consume — see {@see captureArgs()}.
 *
 * Wiring constraint (verified against upstream `McpServer::setup_handlers()`):
 * the adapter instantiates the configured `observability_handler` class-string
 * with `new $class()` — NO constructor arguments are possible, and it is a
 * DIFFERENT object instance than whatever the plugin bootstrap constructs. So
 * all state here is static, set once via {@see configure()} from the plugin
 * bootstrap (matching the plugin's existing global-wiring style), and read by
 * whichever instance the adapter happens to `new` up.
 */
final class McpRequestAuditHandler implements McpObservabilityHandlerInterface
{
    private const EVENT = 'mcp.request';
    private const METHOD = 'tools/call';

    private static ?AuditSink $sink = null;
    private static ?PackResolver $packs = null;
    private static ?TierClassifier $classifier = null;

    /** @var array<string, list<array<string, mixed>>> tool_name => FIFO queue of raw args */
    private static array $rawArgsStash = [];

    /**
     * Service-locator wiring, called once from the plugin bootstrap with the
     * SAME sink + pack resolver the rest of the plugin uses, so this producer's
     * records land in the one audit trail and resolve packs identically.
     */
    public static function configure(AuditSink $sink, PackResolver $packs, ?TierClassifier $classifier = null): void
    {
        self::$sink = $sink;
        self::$packs = $packs;
        self::$classifier = $classifier ?? new TierClassifier();
    }

    /** Forget all static state (tests only). */
    public static function reset(): void
    {
        self::$sink = null;
        self::$packs = null;
        self::$classifier = null;
        self::$rawArgsStash = [];
    }

    /**
     * Registers the raw-args capture hook. Deliberately NOT the observability
     * wiring itself — the adapter owns that (it reads the `observability_handler`
     * class-string out of `mcp_adapter_default_server_config` and instantiates
     * this class on its own). This instance method exists only so bootstrap can
     * register the capture hook via `(new self())->register()`, mirroring the
     * {@see PreToolCallGate} precedent.
     */
    public function register(): void
    {
        add_filter('mcp_adapter_pre_tool_call', [self::class, 'captureArgs'], PHP_INT_MIN, 2);
    }

    /**
     * Hook target for `mcp_adapter_pre_tool_call`. PHP_INT_MIN priority so this
     * runs BEFORE any other subscriber (e.g. {@see PreToolCallGate}) can mutate
     * or short-circuit the args — we want the args the tool was actually CALLED
     * with, not whatever a later gate rewrote them to. Stashed FIFO per tool
     * name so a batch of calls to the same tool within one request pairs up
     * correctly with the `mcp.request` events `record_event()` receives later.
     *
     * Correlation caveat: this filter carries no request_id (verified against
     * upstream signature: `apply_filters($args, $tool_name, $mcp_tool, $mcp)`),
     * so pairing a stash entry with the right `mcp.request` event relies purely
     * on same-process, same-tool-name FIFO ordering — there is no shared key to
     * assert against. Fine for this plugin's synchronous single-request model;
     * would NOT be safe to rely on across an async/queued transport.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed> Unchanged — this hook only observes.
     */
    public static function captureArgs(array $args, string $toolName): array
    {
        self::$rawArgsStash[$toolName][] = $args;

        return $args;
    }

    /**
     * @param array<string, mixed> $tags
     */
    public function record_event(string $event, array $tags = [], ?float $duration_ms = null): void
    {
        if (self::$sink === null || self::$packs === null) {
            return; // not configured — behave like a null-object handler.
        }
        if ($event !== self::EVENT) {
            return;
        }
        if (($tags['component_type'] ?? null) !== 'tool' || ($tags['method'] ?? null) !== self::METHOD) {
            return; // out of remit: prompts/resources, or non-tool events.
        }

        $toolName = is_string($tags['tool_name'] ?? null) ? $tags['tool_name'] : null;
        if ($toolName === null || $toolName === '') {
            return;
        }
        $abilityName = is_string($tags['ability_name'] ?? null) ? $tags['ability_name'] : $toolName;

        $rawArgs = self::consumeStash($toolName);
        $pack = self::$packs->resolve();
        $tier = self::$classifier?->classify($abilityName, $rawArgs ?? [])?->value;

        // The point being proven: fetch the acting user id FRESH, synchronously,
        // from inside record_event() -- i.e. from inside the adapter's own
        // instantiated (no-constructor-args) handler object, not memoized state
        // carried in from elsewhere. If this plugin can do it, so can anyone
        // building audit on the public observability API alone.
        $actor = RequestContext::actor();
        $freshUserId = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        $actor['wp_user'] = $freshUserId > 0 ? $freshUserId : null;

        $mcpMeta = array_filter(
            [
                'request_id'  => $tags['request_id'] ?? null,
                'session_id'  => $tags['session_id'] ?? null,
                'tool_name'   => $toolName,
                'duration_ms' => $duration_ms,
            ],
            static fn ($v) => $v !== null,
        );

        // `input` is nested (unlike the flat args other producers write) because
        // this producer ALSO needs to carry mcp-adapter's own request-shaped
        // metadata (request_id/session_id/duration_ms) and, on the denied path,
        // a marker that raw args were unavailable. AuditRecord::input is
        // `array<string, mixed>` with no fixed inner shape, and AuditLogPage /
        // the CSV export just json_encode() whatever's here, so nesting is safe
        // and doesn't distort AuditRecord's own (fixed, PCI-Req-10) field set —
        // that's exactly why external_effects (a specific "what left the box"
        // list per SPEC §5) was NOT repurposed for this instead.
        $input = Redactor::apply(
            [
                'args' => $rawArgs ?? [
                    '_raw_args_unavailable' => true, // see class docblock: denied calls never reach mcp_adapter_pre_tool_call.
                    'arguments_count' => $tags['params']['arguments_count'] ?? null,
                    'arguments_keys'  => $tags['params']['arguments_keys'] ?? null,
                ],
                '_mcp' => $mcpMeta,
            ],
            $pack->redactsPii(),
        );

        $status = is_string($tags['status'] ?? null) ? $tags['status'] : null;
        $failureReason = is_string($tags['failure_reason'] ?? null) ? $tags['failure_reason'] : null;

        if ($status === 'success') {
            self::$sink->append(AuditRecord::execution(
                id: RequestContext::event(),
                ts: RequestContext::nowUtc(),
                correlationId: RequestContext::correlation(),
                pack: $pack->name,
                actor: $actor,
                ability: $abilityName,
                tier: $tier,
                input: $input,
                result: 'success',
                ip: RequestContext::ip(),
            ));

            return;
        }

        // Every non-success status RequestRouter emits on this event is 'error'
        // (verified against upstream HEAD); we still guard on the literal value
        // rather than assuming, so an unrecognised future status is audited as
        // a failed execution rather than silently mis-filed as a denial.
        if ($status === 'error' && self::isPermissionDenied($failureReason)) {
            self::$sink->append(AuditRecord::decision(
                id: RequestContext::event(),
                ts: RequestContext::nowUtc(),
                correlationId: RequestContext::correlation(),
                pack: $pack->name,
                actor: $actor,
                ability: $abilityName,
                tier: $tier,
                input: $input,
                decision: AuditDecision::Denied,
                ip: RequestContext::ip(),
            ));

            return;
        }

        self::$sink->append(AuditRecord::execution(
            id: RequestContext::event(),
            ts: RequestContext::nowUtc(),
            correlationId: RequestContext::correlation(),
            pack: $pack->name,
            actor: $actor,
            ability: $abilityName,
            tier: $tier,
            input: $input,
            result: $failureReason !== null ? ('error: ' . $failureReason) : 'error',
            ip: RequestContext::ip(),
        ));
    }

    /**
     * FRAGILE BY DESIGN (documented upstream gap, acknowledged by the
     * maintainer): the permission-denied branch of `ToolsHandler::call_tool()`
     * carries no machine-readable outcome or error_code — only this translated
     * message (mcp-adapter's own default, or a WP_Error's custom message,
     * indistinguishable as free text). A locale mismatch between this plugin's
     * PHP process and the adapter's loaded textdomain, or upstream rewording
     * this string, silently breaks the match — the event would then fall
     * through to the generic failed-execution branch instead of being audited
     * as a denial. This is the single biggest "wall" hit building this handler;
     * see UPSTREAM_ISSUE_audit_hook.md.
     */
    private static function isPermissionDenied(?string $failureReason): bool
    {
        return $failureReason !== null && $failureReason === __('Permission denied', 'mcp-adapter');
    }

    /** @return array<string, mixed>|null */
    private static function consumeStash(string $toolName): ?array
    {
        if (empty(self::$rawArgsStash[$toolName])) {
            return null;
        }

        $args = array_shift(self::$rawArgsStash[$toolName]);
        if (empty(self::$rawArgsStash[$toolName])) {
            unset(self::$rawArgsStash[$toolName]);
        }

        return $args;
    }
}
