<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Duplicate-plugin guard in senrogate.php. Runs the real main file in a fresh
 * PHP subprocess (tests/Fixtures/duplicate-guard-runner.php) because the bug
 * is compile-time function hoisting, which cannot be reproduced in-process.
 */
final class DuplicateGuardTest extends TestCase
{
    private const MESSAGE = 'Deactivate Agent Safety first. SenroGate replaces it and keeps its data.';

    /** @return array<string, mixed> */
    private function runMainFile(string $mode): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/Fixtures/duplicate-guard-runner.php') . ' ' . escapeshellarg($mode) . ' 2>&1';
        $raw = (string) shell_exec($cmd);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'main file did not load cleanly (fatal?): ' . $raw);

        return $decoded;
    }

    public function testMainFileIsSyntacticallyValid(): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg(__DIR__ . '/../senrogate.php') . ' 2>&1', $lines, $code);
        $this->assertSame(0, $code, implode("\n", $lines));
    }

    public function testLoadsWithoutFatalWhenAgentSafetyAlreadyLoaded(): void
    {
        $out = $this->runMainFile('old');

        $this->assertSame(0, $out['plugins_loaded'], 'bootstrap must not wire up next to the old plugin');
        $this->assertSame(self::MESSAGE, strip_tags($out['notice']));
        $this->assertStringContainsString('notice-error', $out['notice']);
        $this->assertSame('', $out['notice_without_cap'], 'notice is only for users who can activate plugins');
    }

    public function testActivationIsRefusedWithTheMessageInsteadOfAFatal(): void
    {
        $out = $this->runMainFile('old');

        $this->assertSame(1, $out['activation_registered']);
        $this->assertSame(self::MESSAGE, $out['died']);
        $this->assertSame('senrogate/senrogate.php', $out['deactivated']);
    }

    public function testGuardAlsoTripsWhenOldPluginIsListedActiveButNotYetLoaded(): void
    {
        $out = $this->runMainFile('listed');

        $this->assertSame(0, $out['plugins_loaded']);
        $this->assertArrayHasKey('notice', $out);
    }

    public function testCleanSiteBootsNormallyWithNoNotice(): void
    {
        $out = $this->runMainFile('clean');

        $this->assertSame(1, $out['plugins_loaded']);
        $this->assertArrayNotHasKey('notice', $out);
    }
}
