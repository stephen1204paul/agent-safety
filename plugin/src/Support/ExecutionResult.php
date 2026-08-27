<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

/**
 * Decides whether an executed ability's return value represents success or failure.
 *
 * This is subtle on the shipping stack: Woo abilities dispatch via `rest_do_request()`,
 * which turns most failures (invalid id, validation, REST-permission) into a
 * SUCCESSFUL return carrying a WP_Error-shaped payload with an HTTP status >= 400 —
 * `wp_after_execute_ability` still fires. So a clean success requires the result NOT
 * to look like that error shape. (A true WP_Error / fatal never reaches `after` at
 * all; callers treat the still-in-flight case as failure.)
 *
 * Shared by the audit log (success|failure labelling) and the approval gate
 * (finalize a reservation only on real success, else roll it back) so both judge an
 * execution identically.
 */
final class ExecutionResult
{
    /** Keys of a serialized WP_Error (see WP_REST_Server::error_to_response). */
    private const WP_ERROR_KEYS = ['code', 'message', 'data', 'additional_errors', 'additional_data'];

    /** @param mixed $result */
    public static function isSuccess($result): bool
    {
        return !self::isFailure($result);
    }

    /** @param mixed $result */
    public static function isFailure($result): bool
    {
        if (is_wp_error($result)) {
            return true;
        }

        if (is_array($result)) {
            $status = $result['data']['status'] ?? ($result['status'] ?? null);
            if (is_int($status) && $status >= 400) {
                return true;
            }
            // WP_Error-shaped payload (code + message) with no success data: any
            // key outside the WP_Error array shape means a real result that
            // happens to carry `code`/`message` fields, which must stay a success —
            // misjudging it would roll an already-spent approval grant back.
            if (
                isset($result['code'], $result['message'])
                && is_string($result['code'])
                && array_diff_key($result, array_flip(self::WP_ERROR_KEYS)) === []
            ) {
                return true;
            }
        }

        return false;
    }
}
