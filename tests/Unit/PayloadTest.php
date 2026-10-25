<?php

declare(strict_types=1);

namespace MrCache\Tests\Unit;

use MrCache\Contracts\CacheClientInterface;
use MrCache\Contracts\KeyGeneratorInterface;
use MrCache\Services\CacheManager;
use MrCache\Services\KeyGenerator;
use MrCache\Services\QueryColumnMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PayloadTest extends TestCase
{
    private function manager(array $config = []): CacheManager
    {
        return new CacheManager($this->createMock(CacheClientInterface::class), $this->createMock(KeyGeneratorInterface::class), new QueryColumnMatcher(), $config);
    }

    public static function malformedPayloads(): array
    {
        return array_map(fn ($raw) => [$raw], [
            '', 'null', 'false', '0', '[]', '{}', '{"version":1,"data":[{"id":1}]}',
            '{"version":2,"data":[]}', '{"version":2,"data":[false]}', '{"version":2,"data":[[]]}',
            '{"version":2,"data":[{"id":[1]}]}', 'O:8:"stdClass":0:{}', "C::\x00",
        ]);
    }

    #[DataProvider('malformedPayloads')]
    public function test_untrusted_or_legacy_payloads_are_misses(string $raw): void
    {
        $this->assertSame([], $this->manager()->decodePayload($raw));
    }

    public function test_false_null_zero_and_empty_strings_are_preserved_in_rows(): void
    {
        $data = ['version' => 2, 'data' => [['a' => false, 'b' => null, 'c' => 0, 'd' => '']]];
        $this->assertSame($data, $this->manager()->decodePayload(json_encode($data)));
    }

    public function test_payload_size_limit_is_enforced(): void
    {
        $this->assertSame([], $this->manager(['max_payload_bytes' => 5])->decodePayload('{"version":2,"data":[{"id":1}]}'));
        $this->expectException(\InvalidArgumentException::class);
        $this->manager(['max_payload_bytes' => 0]);
    }

    public function test_prefix_cannot_be_empty_or_a_scan_glob(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new KeyGenerator(['prefix' => '*']);
    }
}
