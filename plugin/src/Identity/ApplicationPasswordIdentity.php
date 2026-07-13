<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Identity;

use WP_Application_Passwords;

/**
 * Identity provider for WordPress core Application Passwords (WP >= 5.6):
 * a REST/MCP request authenticated with an application password is bound to
 * that specific credential, namespaced "app:{uuid}" (a token id, never the
 * secret itself).
 */
final class ApplicationPasswordIdentity implements IdentityProvider
{
    public function currentTokens(): array
    {
        if (!function_exists('rest_get_authenticated_app_password')) {
            return [];
        }

        $uuid = rest_get_authenticated_app_password();

        return is_string($uuid) && $uuid !== '' ? ['app:' . $uuid] : [];
    }

    /** @return array<string, string> */
    public function bindableTokens(): array
    {
        if (!class_exists(WP_Application_Passwords::class) || !function_exists('get_users')) {
            return [];
        }

        $out = [];
        foreach (get_users() as $user) {
            $passwords = WP_Application_Passwords::get_user_application_passwords((int) $user->ID);
            foreach ($passwords as $password) {
                $uuid = $password['uuid'] ?? null;
                if (!is_string($uuid) || $uuid === '') {
                    continue;
                }
                $name = is_string($password['name'] ?? null) ? $password['name'] : '';
                $out['app:' . $uuid] = trim(sprintf('%s — %s', $user->user_login, $name), ' —');
            }
        }

        return $out;
    }

    public function label(): string
    {
        return __('Application Passwords', 'agent-safety');
    }
}
