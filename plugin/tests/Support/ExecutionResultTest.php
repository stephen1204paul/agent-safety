<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Support\ExecutionResult;
use WP_Error;

final class ExecutionResultTest extends TestCase
{
    /** @return iterable<string, array{mixed, bool}> */
    public static function results(): iterable
    {
        yield 'wp_error object' => [new WP_Error('boom', 'no'), true];
        yield 'rest error with data.status' => [['code' => 'rest_not_found', 'message' => 'Nope', 'data' => ['status' => 404]], true];
        yield 'top-level status >= 400' => [['status' => 500], true];
        yield 'bare code+message (error shape)' => [['code' => 'invalid', 'message' => 'bad'], true];
        yield 'success carrying code+message plus data' => [['code' => 'ORDER-1042', 'message' => 'Shipped', 'id' => 1042], false];
        yield 'success with 2xx status' => [['status' => 201, 'id' => 7], false];
        yield 'plain success array' => [['id' => 7], false];
        yield 'scalar' => [true, false];
        yield 'null' => [null, false];
    }

    /**
     * @dataProvider results
     * @param mixed $result
     */
    public function testClassification($result, bool $failure): void
    {
        $this->assertSame($failure, ExecutionResult::isFailure($result));
        $this->assertSame(!$failure, ExecutionResult::isSuccess($result));
    }
}
