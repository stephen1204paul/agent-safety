<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Specflux\AgentSafety\Plugin\Support\UninstallManifest;

/**
 * §3.6 item 3: the opted-in uninstall path works from an explicit list
 * ({@see UninstallManifest}), and THIS test is what keeps that list honest —
 * it statically re-derives, from the real source under `plugin/src`, every
 * option/cron-hook/transient-prefix name the plugin actually writes, and
 * fails naming anything it finds that {@see UninstallManifest} doesn't list.
 *
 * "Statically re-derives" means: walk every .php file under plugin/src for
 * calls to get_option/update_option/add_option/delete_option,
 * wp_schedule_event/wp_schedule_single_event and set_transient; resolve each
 * call's name argument by evaluating the literal expression there, following
 * a SHORT, bounded chain of indirection when the argument is not a bare
 * string literal:
 *   - a class-constant reference (`self::X` / `SomeClass::X`), resolved via
 *     {@see ReflectionClass::getConstant()} against the REAL loaded class —
 *     not a second, hand-maintained copy of what the constant says;
 *   - string concatenation, resolved left-to-right and truncated at the
 *     first unresolvable segment (the plugin's transient keys are always a
 *     literal/constant PREFIX concatenated with a per-call hash or subject,
 *     so the resolved prefix is exactly what uninstall.php needs to `LIKE`
 *     against);
 *   - a bare local variable, resolved to whatever expression it was last
 *     assigned from in the same method, or — when it is a parameter instead
 *     — to the actual argument passed at its position by the (sole, in this
 *     codebase) in-class caller;
 *   - `$this->method(...)` / `self::method(...)`, resolved to that method's
 *     first `return` expression.
 *
 * Anything left unresolved after that is a hard failure in its own right: an
 * option/cron/transient name this scan cannot statically verify is not a
 * name it can silently wave through, so the single test below asserts BOTH
 * that nothing was left unresolved AND that everything resolved is listed.
 */
final class UninstallManifestCoverageTest extends TestCase
{
    private const ARG_INDEX = [
        'get_option' => 0,
        'update_option' => 0,
        'add_option' => 0,
        'delete_option' => 0,
        'wp_schedule_event' => 2,
        'wp_schedule_single_event' => 1,
        'set_transient' => 0,
    ];

    public function testEveryOptionCronHookAndTransientPrefixIsInTheManifest(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $files = self::phpFiles($srcDir);

        // Load every class under plugin/src so ReflectionClass can read the
        // REAL constant values below (a class not yet referenced elsewhere
        // in the suite is still lazily-autoloadable, but requiring directly
        // here means this test does not depend on suite ordering).
        foreach ($files as $file) {
            require_once $file;
        }

        /** @var array<string, string> $fileContents file path => source with comments blanked out (same byte length/line numbers, so a docblock mentioning the word "class" can never be mistaken for a declaration) */
        $fileContents = [];
        /** @var array<string, string> $fileClass file path => FQCN (or absent if the file declares no class) */
        $fileClass = [];
        /** @var array<string, string> $shortIndex short class name => FQCN */
        $shortIndex = [];
        foreach ($files as $file) {
            $content = self::stripComments((string) file_get_contents($file));
            $fileContents[$file] = $content;
            $fqcn = self::declaredClass($content);
            if ($fqcn !== null) {
                $fileClass[$file] = $fqcn;
                $shortIndex[self::shortName($fqcn)] = $fqcn;
            }
        }

        $foundOptions = [];
        $foundCronHooks = [];
        $foundTransientPrefixes = [];
        $unresolved = [];

        foreach ($files as $file) {
            $content = $fileContents[$file];
            $currentClass = $fileClass[$file] ?? null;

            foreach (self::callSites($content) as [$funcName, $argsStr, $offset]) {
                $argIndex = self::ARG_INDEX[$funcName];
                $args = self::splitTopLevel($argsStr, ',');
                if (!isset($args[$argIndex])) {
                    continue;
                }

                $enclosing = self::findEnclosingMethod($content, $offset);
                $resolved = self::resolveExpr(
                    trim($args[$argIndex]),
                    $currentClass,
                    $content,
                    $shortIndex,
                    $enclosing['name'] ?? null,
                    $enclosing['body'] ?? null,
                );

                if ($resolved === null) {
                    $line = substr_count(substr($content, 0, $offset), "\n") + 1;
                    $unresolved[] = sprintf('%s:%d %s(%s)', $file, $line, $funcName, trim($args[$argIndex]));
                    continue;
                }

                // get_option() reads are deliberately NOT required to be listed: this plugin reads
                // at least one WordPress-core option it doesn't own (admin_email, as a notification
                // fallback in ApprovalNotifier) and uninstall must never delete a core option just
                // because the plugin happened to read it once. Anything the plugin actually WRITES
                // (update_option/add_option) or explicitly clears (delete_option) is plugin-owned
                // by definition and must be listed.
                if (in_array($funcName, ['update_option', 'add_option', 'delete_option'], true)) {
                    $foundOptions[$resolved] = true;
                } elseif ($funcName === 'get_option') {
                    // Deliberately not required: see the comment above.
                    continue;
                } elseif (in_array($funcName, ['wp_schedule_event', 'wp_schedule_single_event'], true)) {
                    $foundCronHooks[$resolved] = true;
                } elseif ($funcName === 'set_transient') {
                    $foundTransientPrefixes[$resolved] = true;
                }
            }
        }

        $this->assertSame(
            [],
            $unresolved,
            "Found option/cron/transient call site(s) whose name argument this scan could not statically "
            . "resolve; teach the scan the pattern or simplify the call site:\n" . implode("\n", $unresolved),
        );

        $missingOptions = array_diff(array_keys($foundOptions), UninstallManifest::OPTIONS);
        $missingCronHooks = array_diff(array_keys($foundCronHooks), UninstallManifest::CRON_HOOKS);
        $missingTransientPrefixes = array_diff(array_keys($foundTransientPrefixes), UninstallManifest::TRANSIENT_PREFIXES);

        $this->assertSame([], array_values($missingOptions), 'Option(s) used in plugin/src but missing from UninstallManifest::OPTIONS');
        $this->assertSame([], array_values($missingCronHooks), 'Cron hook(s) used in plugin/src but missing from UninstallManifest::CRON_HOOKS');
        $this->assertSame([], array_values($missingTransientPrefixes), 'Transient prefix(es) used in plugin/src but missing from UninstallManifest::TRANSIENT_PREFIXES');
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $fileInfo) {
            /** @var \SplFileInfo $fileInfo */
            if ($fileInfo->getExtension() === 'php') {
                $files[] = $fileInfo->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Blank out every `//`, `#` and `/* *\/` comment to spaces (newlines kept
     * as-is), so byte offsets and line numbers in the result exactly match
     * the original file. Prose in a docblock (e.g. "...a class dependency
     * beyond this one...") would otherwise be indistinguishable from a real
     * `class Foo` declaration to a plain regex.
     */
    private static function stripComments(string $source): string
    {
        $out = '';
        $len = strlen($source);
        $inString = null;
        for ($i = 0; $i < $len; $i++) {
            $ch = $source[$i];

            if ($inString !== null) {
                $out .= $ch;
                if ($ch === '\\') {
                    $i++;
                    if ($i < $len) {
                        $out .= $source[$i];
                    }
                    continue;
                }
                if ($ch === $inString) {
                    $inString = null;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString = $ch;
                $out .= $ch;
                continue;
            }

            if ($ch === '/' && ($source[$i + 1] ?? '') === '/') {
                while ($i < $len && $source[$i] !== "\n") {
                    $out .= $source[$i] === "\n" ? "\n" : ' ';
                    $i++;
                }
                $i--;
                continue;
            }

            if ($ch === '#' && ($source[$i + 1] ?? '') !== '[') {
                while ($i < $len && $source[$i] !== "\n") {
                    $out .= ' ';
                    $i++;
                }
                $i--;
                continue;
            }

            if ($ch === '/' && ($source[$i + 1] ?? '') === '*') {
                $out .= '  ';
                $i += 2;
                while ($i < $len && !($source[$i] === '*' && ($source[$i + 1] ?? '') === '/')) {
                    $out .= $source[$i] === "\n" ? "\n" : ' ';
                    $i++;
                }
                $out .= '  ';
                $i++;
                continue;
            }

            $out .= $ch;
        }

        return $out;
    }

    private static function declaredClass(string $content): ?string
    {
        if (!preg_match('/^\s*namespace\s+([^;]+);/m', $content, $ns)) {
            return null;
        }
        if (!preg_match('/\b(?:final\s+|abstract\s+)?class\s+(\w+)/', $content, $cls)) {
            return null;
        }

        return trim($ns[1]) . '\\' . $cls[1];
    }

    private static function shortName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return end($parts);
    }

    /**
     * Every call site in $content of one of the {@see ARG_INDEX} functions,
     * guarded against matching a method with the same name (`->foo(` /
     * `::foo(`).
     *
     * @return list<array{0: string, 1: string, 2: int}> [funcName, rawArgsString, byteOffsetOfMatch]
     */
    private static function callSites(string $content): array
    {
        $names = implode('|', array_map('preg_quote', array_keys(self::ARG_INDEX)));
        $sites = [];
        if (!preg_match_all('/(?<![\w>:])(' . $names . ')\s*\(/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($matches[1] as $i => [$funcName, $offset]) {
            $openParen = strpos($content, '(', $offset);
            if ($openParen === false) {
                continue;
            }
            $closeParen = self::matchBracket($content, $openParen, '(', ')');
            if ($closeParen === -1) {
                continue;
            }
            $argsStr = substr($content, $openParen + 1, $closeParen - $openParen - 1);
            $sites[] = [$funcName, $argsStr, $matches[0][$i][1]];
        }

        return $sites;
    }

    /** Index of the bracket matching the one at $openPos, or -1. Skips over quoted strings. */
    private static function matchBracket(string $s, int $openPos, string $open, string $close): int
    {
        $depth = 0;
        $len = strlen($s);
        $inString = null; // "'" or '"' while inside a string literal
        for ($i = $openPos; $i < $len; $i++) {
            $ch = $s[$i];
            if ($inString !== null) {
                if ($ch === '\\') {
                    $i++;
                    continue;
                }
                if ($ch === $inString) {
                    $inString = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $inString = $ch;
                continue;
            }
            if ($ch === $open) {
                $depth++;
            } elseif ($ch === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }

    /** Split $expr on $delim at depth 0 only (skips over ()/[]/quoted strings). */
    private static function splitTopLevel(string $expr, string $delim): array
    {
        $parts = [];
        $depth = 0;
        $inString = null;
        $current = '';
        $len = strlen($expr);
        for ($i = 0; $i < $len; $i++) {
            $ch = $expr[$i];
            if ($inString !== null) {
                $current .= $ch;
                if ($ch === '\\') {
                    $i++;
                    if ($i < $len) {
                        $current .= $expr[$i];
                    }
                    continue;
                }
                if ($ch === $inString) {
                    $inString = null;
                }
                continue;
            }
            if ($ch === "'" || $ch === '"') {
                $inString = $ch;
                $current .= $ch;
                continue;
            }
            if ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' || $ch === ']') {
                $depth--;
            }
            if ($depth === 0 && $ch === $delim) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $parts[] = $current;

        return $parts;
    }

    /**
     * Resolve one PHP expression (a call-site argument, or a sub-expression
     * reached through indirection) to a static string, or null when it is
     * genuinely dynamic (a function call this scan does not attempt to
     * evaluate, e.g. `substr(...)`/`md5(...)`).
     *
     * $methodName/$methodBody are the enclosing method's name/brace-matched
     * body for a bare-variable lookup ({@see resolveBareVariable()}); null
     * when $expr sits outside any method (never happens for the call sites
     * this scan targets, but kept nullable rather than assumed).
     *
     * @param array<string, string> $shortIndex short class name => FQCN
     */
    private static function resolveExpr(
        string $expr,
        ?string $currentClass,
        string $fileContent,
        array $shortIndex,
        ?string $methodName,
        ?string $methodBody,
        int $depth = 0,
    ): ?string {
        $expr = trim($expr);
        if ($expr === '' || $depth > 6) {
            return null;
        }

        // Plain string literal (no interpolation involved in any call site this scan covers).
        if (preg_match('/^\'((?:[^\'\\\\]|\\\\.)*)\'$/', $expr, $m)) {
            return stripcslashes($m[1]);
        }
        if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $expr, $m) && !str_contains($m[1], '$')) {
            return stripcslashes($m[1]);
        }

        // Concatenation: resolve left-to-right, stop (but keep what's resolved) at the first miss.
        $parts = self::splitTopLevel($expr, '.');
        if (count($parts) > 1) {
            $result = '';
            foreach ($parts as $part) {
                $piece = self::resolveExpr($part, $currentClass, $fileContent, $shortIndex, $methodName, $methodBody, $depth + 1);
                if ($piece === null) {
                    break;
                }
                $result .= $piece;
            }

            return $result !== '' ? $result : null;
        }

        // Pure class-constant reference: self::X / static::X / ClassName::X.
        if (preg_match('/^(self|static|[A-Za-z_][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)$/', $expr, $m)) {
            $fqcn = in_array($m[1], ['self', 'static'], true) ? $currentClass : ($shortIndex[$m[1]] ?? null);
            if ($fqcn === null || !class_exists($fqcn)) {
                return null;
            }
            $ref = new ReflectionClass($fqcn);
            if (!$ref->hasConstant($m[2])) {
                return null;
            }
            $value = $ref->getConstant($m[2]);

            return is_string($value) ? $value : null;
        }

        // $this->method(...) / self::method(...) spanning the WHOLE expression: follow to that
        // method's first `return` expression.
        if (preg_match('/^(?:\$this->|self::)([A-Za-z_][A-Za-z0-9_]*)\(/', $expr, $m)) {
            $openParen = strpos($expr, '(');
            $closeParen = $openParen !== false ? self::matchBracket($expr, $openParen, '(', ')') : -1;
            if ($currentClass !== null && $openParen !== false && $closeParen === strlen($expr) - 1) {
                $calleeBody = self::methodBody($fileContent, $m[1]);

                return $calleeBody !== null
                    ? self::resolveReturn($calleeBody, $currentClass, $fileContent, $shortIndex, $m[1], $depth)
                    : null;
            }

            return null;
        }

        // Bare local variable: either it was assigned earlier in the same method, or it is one
        // of that method's parameters, in which case follow to what its (sole, in this codebase)
        // in-class caller actually passed.
        if (preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)$/', $expr, $m) && $currentClass !== null && $methodName !== null && $methodBody !== null) {
            return self::resolveBareVariable($m[1], $methodBody, $methodName, $currentClass, $fileContent, $shortIndex, $depth);
        }

        return null;
    }

    /**
     * Bare-variable resolution: look for a local assignment first; when
     * there isn't one, the variable must be a parameter of $methodName, so
     * resolve to whatever its (sole, in this codebase) in-class caller
     * actually passed at that position.
     *
     * @param array<string, string> $shortIndex
     */
    private static function resolveBareVariable(
        string $varName,
        string $methodBody,
        string $methodName,
        string $currentClass,
        string $fileContent,
        array $shortIndex,
        int $depth,
    ): ?string {
        if (preg_match('/\$' . preg_quote($varName, '/') . '\s*=\s*(.+?);/s', $methodBody, $m)) {
            return self::resolveExpr(trim($m[1]), $currentClass, $fileContent, $shortIndex, $methodName, $methodBody, $depth + 1);
        }

        if (!preg_match('/function\s+' . preg_quote($methodName, '/') . '\s*\(([^)]*)\)/', $fileContent, $sig)) {
            return null;
        }
        $params = self::splitTopLevel($sig[1], ',');
        $position = null;
        foreach ($params as $i => $param) {
            if (preg_match('/\$' . preg_quote($varName, '/') . '\b/', $param)) {
                $position = $i;
                break;
            }
        }
        if ($position === null) {
            return null;
        }

        if (!preg_match('/(?:\$this->|self::)' . preg_quote($methodName, '/') . '\s*\(/', $fileContent, $callMatch, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $openParen = strpos($fileContent, '(', $callMatch[0][1]);
        if ($openParen === false) {
            return null;
        }
        $closeParen = self::matchBracket($fileContent, $openParen, '(', ')');
        if ($closeParen === -1) {
            return null;
        }
        $callArgs = self::splitTopLevel(substr($fileContent, $openParen + 1, $closeParen - $openParen - 1), ',');
        if (!isset($callArgs[$position])) {
            return null;
        }

        $callerEnclosing = self::findEnclosingMethod($fileContent, $callMatch[0][1]);

        return self::resolveExpr(
            trim($callArgs[$position]),
            $currentClass,
            $fileContent,
            $shortIndex,
            $callerEnclosing['name'] ?? null,
            $callerEnclosing['body'] ?? null,
            $depth + 1,
        );
    }

    /** Resolve the first `return <expr>;` inside $methodBody, in the context of method $methodName. */
    private static function resolveReturn(
        string $methodBody,
        string $currentClass,
        string $fileContent,
        array $shortIndex,
        string $methodName,
        int $depth,
    ): ?string {
        if (!preg_match('/return\s+(.+?);/s', $methodBody, $m)) {
            return null;
        }

        return self::resolveExpr(trim($m[1]), $currentClass, $fileContent, $shortIndex, $methodName, $methodBody, $depth + 1);
    }

    /** Brace-matched body of method $name in $fileContent, or null if not found (e.g. an abstract/interface method). */
    private static function methodBody(string $fileContent, string $name): ?string
    {
        if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)[^{;]*\{/', $fileContent, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $openBrace = $m[0][1] + strlen($m[0][0]) - 1;
        $closeBrace = self::matchBracket($fileContent, $openBrace, '{', '}');
        if ($closeBrace === -1) {
            return null;
        }

        return substr($fileContent, $openBrace + 1, $closeBrace - $openBrace - 1);
    }

    /**
     * The method (name + brace-matched body) in $fileContent whose body
     * contains byte offset $offset, or null when $offset sits outside any
     * method.
     *
     * @return array{name: string, body: string}|null
     */
    private static function findEnclosingMethod(string $fileContent, int $offset): ?array
    {
        if (!preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\([^)]*\)[^{;]*\{/', $fileContent, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($matches[0] as $i => [$sig, $sigOffset]) {
            $openBrace = $sigOffset + strlen($sig) - 1;
            $closeBrace = self::matchBracket($fileContent, $openBrace, '{', '}');
            if ($closeBrace === -1) {
                continue;
            }
            if ($offset > $openBrace && $offset < $closeBrace) {
                return [
                    'name' => $matches[1][$i][0],
                    'body' => substr($fileContent, $openBrace + 1, $closeBrace - $openBrace - 1),
                ];
            }
        }

        return null;
    }
}
