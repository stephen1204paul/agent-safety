<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Fixtures;

/**
 * The frozen WP_Error contract a blocked call is reported with — the ONE shape
 * both gate seams emit (via Verdict::error()) and the shape consumers such as
 * SenroFlux parse. Framework-free on purpose: this file is loaded by path
 * from other repositories' test suites, so it must not depend on PHPUnit or on
 * any Agent Safety class (docs/adr/0001-single-verdict-pipeline.md).
 *
 * Producer test: plugin/tests/Verdict/VerdictErrorContractTest.php.
 * Consumer test: senroflux/tests/Tools/AgentSafetyContractTest.php.
 *
 * Changing anything here is a contract change: bump the plugin's minor
 * version and update every consumer in the same release.
 */
final class VerdictErrorFixture
{
    public const DENY_CODE = 'agent_safety_denied';
    public const APPROVAL_CODE = 'approval_required';

    /**
     * Every distinct error shape the pipeline can produce, keyed by a stable
     * scenario name. `data` is the exact array `WP_Error::get_error_data()`
     * returns — key PRESENCE matters: the deny error always carries all three
     * keys (tier may be null), the approval error drops null-valued keys.
     *
     * @return array<string, array{code: string, verb: string, tier: ?int, approval_id: ?string, data: array<string, mixed>}>
     */
    public static function cases(): array
    {
        return [
            'deny with tier' => [
                'code' => self::DENY_CODE,
                'verb' => 'woocommerce/orders-update',
                'tier' => 1,
                'approval_id' => null,
                'data' => ['status' => 403, 'verb' => 'woocommerce/orders-update', 'tier' => 1],
            ],
            'deny without tier' => [
                'code' => self::DENY_CODE,
                'verb' => 'woocommerce/unknown-verb',
                'tier' => null,
                'approval_id' => null,
                'data' => ['status' => 403, 'verb' => 'woocommerce/unknown-verb', 'tier' => null],
            ],
            'approval required with id' => [
                'code' => self::APPROVAL_CODE,
                'verb' => 'woocommerce/orders-delete',
                'tier' => 2,
                'approval_id' => 'apr_0123456789abcdef',
                'data' => ['status' => 202, 'verb' => 'woocommerce/orders-delete', 'tier' => 2, 'approval_id' => 'apr_0123456789abcdef'],
            ],
            'approval required without id (no approval store)' => [
                'code' => self::APPROVAL_CODE,
                'verb' => 'woocommerce/orders-delete',
                'tier' => 2,
                'approval_id' => null,
                'data' => ['status' => 202, 'verb' => 'woocommerce/orders-delete', 'tier' => 2],
            ],
        ];
    }
}
