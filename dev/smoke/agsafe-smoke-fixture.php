<?php
/**
 * Plugin Name: Agent Safety Smoke Fixtures
 * Description: LOCAL DEV ONLY. Registers a synthetic governed namespace with a
 *              spend-style ability, two smoke packs (capped + shadow), and
 *              captures wp_mail / webhook HTTP so the 0.2 features can be
 *              exercised over real HTTP. Never ship.
 */

defined('ABSPATH') || exit;

// ---- Abilities -------------------------------------------------------------

add_action('wp_abilities_api_categories_init', static function (): void {
    wp_register_ability_category('smoke', [
        'label'       => 'Smoke',
        'description' => 'Synthetic abilities for the Agent Safety live smoke test.',
    ]);
});

add_action('wp_abilities_api_init', static function (): void {
    wp_register_ability('agsafe-smoke/spend', [
        'label'         => 'Smoke spend',
        'description'   => 'Synthetic side-effecting ability that "spends" an amount.',
        'category'      => 'smoke',
        'input_schema'  => [
            'type'                 => 'object',
            'properties'           => [
                'amount' => ['type' => 'number'],
                'note'   => ['type' => 'string'],
            ],
            'additionalProperties' => true,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array {
            $n = (int) get_option('agsafe_smoke_exec_spend', 0);
            update_option('agsafe_smoke_exec_spend', $n + 1, false);
            return ['ok' => true, 'spent' => $input['amount'] ?? null, 'executions' => $n + 1];
        },
        'permission_callback' => static fn (): bool => current_user_can('read'),
        'meta'          => ['show_in_rest' => true],
    ]);

    wp_register_ability('agsafe-smoke/blocked', [
        'label'         => 'Smoke blocked',
        'description'   => 'Synthetic irreversible ability used for shadow-mode checks.',
        'category'      => 'smoke',
        'input_schema'  => [
            'type'                 => 'object',
            'properties'           => ['target' => ['type' => 'string']],
            'additionalProperties' => true,
        ],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (array $input): array {
            $n = (int) get_option('agsafe_smoke_exec_blocked', 0);
            update_option('agsafe_smoke_exec_blocked', $n + 1, false);
            return ['ok' => true, 'executions' => $n + 1];
        },
        'permission_callback' => static fn (): bool => current_user_can('read'),
        'meta'          => ['show_in_rest' => true],
    ]);
});

// ---- Agent Safety wiring ---------------------------------------------------

add_filter('agent_safety_governed_namespaces', static function (array $namespaces): array {
    $namespaces[] = 'agsafe-smoke/';
    return $namespaces;
});

add_filter('agent_safety_verb_map', static function (array $map): array {
    $map['agsafe-smoke/spend']   = 1; // SideEffecting
    $map['agsafe-smoke/blocked'] = 2; // Irreversible
    return $map;
});

// ---- Core-namespace MCP exposure (0.3 stage-4 assertions) ------------------

// WordPress core's three abilities are NOT auto-exposed on the default MCP
// server (they carry no meta.public / meta.mcp.public). Opt them in so the
// smoke can drive tools/call core-get-site-info etc. through mcp-adapter.
add_filter('wp_register_ability_args', static function (array $args, string $name) {
    if (!str_starts_with($name, 'core/')) {
        return $args;
    }
    $meta = $args['meta'] ?? [];
    $mcp = is_array($meta['mcp'] ?? null) ? $meta['mcp'] : [];
    $mcp['public'] = true;
    $meta['mcp'] = $mcp;
    $args['meta'] = $meta;
    return $args;
}, 10, 2);

// An ability inside the governed core/ namespace that the verb map does NOT
// classify: the gate must deny it with reason unknown_verb (D23 fail-closed).
add_action('wp_abilities_api_init', static function (): void {
    // This action fires once per request, so no presence guard is needed here
    // (and WP_Abilities_Registry::get_registered() would _doing_it_wrong on
    // an unknown name anyway).
    wp_register_ability('core/nope', [
        'label'         => 'Smoke unknown core verb',
        'description'   => 'Governed namespace, deliberately unmapped: must be denied as unknown_verb.',
        'category'      => 'smoke',
        'input_schema'  => ['type' => 'object'],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (): array {
            update_option('agsafe_smoke_exec_nope', (int) get_option('agsafe_smoke_exec_nope', 0) + 1, false);
            return ['ok' => true];
        },
        'permission_callback' => static fn (): bool => current_user_can('read'),
        'meta'          => ['show_in_rest' => true, 'mcp' => ['public' => true]],
    ]);
});

// The default MCP server ships only its own meta-tools; ability-derived
// tools are opt-in through this filter's `tools` list (ability ids; the
// adapter derives tool names by its own convention). Add the core verbs the
// smoke drives over HTTP.
add_filter('mcp_adapter_default_server_config', static function (array $config): array {
    $config['tools'] = array_values(array_unique(array_merge(
        is_array($config['tools'] ?? null) ? $config['tools'] : [],
        [
            'core/get-site-info',
            'core/get-environment-info',
            'core/get-user-info',
            'core/nope',
        ]
    )));
    return $config;
});

// ---- Notification capture --------------------------------------------------

add_filter('agent_safety_pack_registry', static function ($registry) {
    $registry->register(new \Specflux\AgentSafety\Packs\Pack(
        name: 'smoke-capped',
        allow: ['agsafe-smoke/*'],
        argumentCaps: [new \Specflux\AgentSafety\Packs\ArgumentCap(
            id: 'smokecap',
            verbs: 'agsafe-smoke/spend',
            argPath: 'amount',
            approvalAbove: 100.0,
            maxPerCall: 500.0,
            maxTotalPerDay: 400.0,
        )],
    ));
    $registry->register(new \Specflux\AgentSafety\Packs\Pack(
        name: 'smoke-shadow',
        allow: [],
    ));
    return $registry;
});

// ---- Notification capture --------------------------------------------------

// Short-circuit wp_mail and record what would have been sent.
add_filter('pre_wp_mail', static function ($null, array $atts) {
    $log   = (array) get_option('agsafe_smoke_mail_log', []);
    $log[] = ['to' => $atts['to'], 'subject' => $atts['subject'], 'message' => $atts['message']];
    update_option('agsafe_smoke_mail_log', $log, false);
    return true;
}, 10, 2);

// Intercept the webhook POST at the HTTP layer and record URL + body.
add_filter('pre_http_request', static function ($pre, array $args, string $url) {
    if (!str_contains($url, 'smoke.invalid')) {
        return $pre;
    }
    $log   = (array) get_option('agsafe_smoke_http_log', []);
    $log[] = ['url' => $url, 'body' => $args['body'] ?? null, 'blocking' => $args['blocking'] ?? null];
    update_option('agsafe_smoke_http_log', $log, false);
    return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);
