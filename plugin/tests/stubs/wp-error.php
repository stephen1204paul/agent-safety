<?php

/**
 * Test-only stand-in for WordPress's WP_Error — just enough of its public API
 * (constructor, get_error_code(), get_error_message(), get_error_data()) for
 * the plugin's gate seams, which construct one on every denial, pending
 * approval, and execution failure. A real WP load order (wp-env) always wins
 * — see bootstrap.php's class_exists() guard.
 */

declare(strict_types=1);

class WP_Error
{
    /** @var array<string, list<string>> error code => messages */
    private array $errors = [];

    /** @var array<string, mixed> error code => data */
    private array $errorData = [];

    /** @param mixed $data */
    public function __construct(string $code = '', string $message = '', $data = '')
    {
        if ($code === '') {
            return;
        }

        $this->errors[$code][] = $message;
        if ($data !== '') {
            $this->errorData[$code] = $data;
        }
    }

    /** @return string|int */
    public function get_error_code()
    {
        $codes = array_keys($this->errors);

        return $codes[0] ?? '';
    }

    public function get_error_message(?string $code = null): string
    {
        $code ??= (string) $this->get_error_code();

        return $this->errors[$code][0] ?? '';
    }

    /** @return mixed */
    public function get_error_data(?string $code = null)
    {
        $code ??= (string) $this->get_error_code();

        return $this->errorData[$code] ?? null;
    }
}
