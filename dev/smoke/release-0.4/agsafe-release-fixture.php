<?php
/**
 * mu-plugin fixture for the release-0.4 e2e proof harness. Mu-plugins load
 * unconditionally regardless of which agent-safety zip (0.3 or 0.4.0) is
 * currently installed, so this one filter covers both phases of P2.
 *
 * `agent_safety_enable_grants` is off by default (Specflux\AgentSafety\Plugin\
 * Verdict\GrantGate::enabled(): `true === apply_filters('agent_safety_enable_grants', false)`)
 * — the whole pre-approval-grant feature (AS-12) is opt-in. P2 needs a real
 * grant row seeded via `agent_safety()->grants()->issue()`
 * (seed-shadow-and-grant.php), which returns null and refuses when the
 * feature switch is off. This harness turns it on for the duration of the
 * proof run only (a throwaway wp-env instance), never for a real site.
 */
add_filter('agent_safety_enable_grants', '__return_true');

/**
 * Woo's own MCP transport (`/wp-json/woocommerce/mcp`, used by P2/P3/P4/P5)
 * requires HTTPS by default; wp-env has no TLS. Same dev-only filter the
 * dev/smoke/woo-native-mcp leg's fixture already uses — never ship this.
 */
add_filter('woocommerce_mcp_allow_insecure_transport', '__return_true');
