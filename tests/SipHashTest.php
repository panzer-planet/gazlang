<?php

namespace GazLang\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * SipHash-1-3 (vm/siphash.c), which hashes every string and map key under a key drawn for the
 * process, so a client choosing keys can't aim at one bucket. The expected values are CPython's:
 * its hash() of bytes is SipHash-1-3, and PYTHONHASHSEED=N derives its key from a published
 * generator, so they share no code with this one. Lengths are either side of the 8-byte block.
 */
class SipHashTest extends TestCase
{
    private const LENGTHS = [1, 7, 8, 9, 15, 16, 17, 24, 31, 32, 33];

    /**
     * Each key (two hex words) with the hashes CPython gives for LENGTHS
     *
     * @return array<string, array{string, string, string}>
     */
    public static function keys(): array
    {
        return [
            '0000000000000000 0000000000000000' => ['0000000000000000', '0000000000000000', '5218271218080536521,9975858737955817898,3945583116760701931,5869906781989555199,1868218806581060834,15116698709512780667,13713849710794780000,4927829853553092329,17931523999783813243,2131602486069241629,8206645638278696407'],
            'aed66ce184be2329 ebe9bbf1f1499052' => ['aed66ce184be2329', 'ebe9bbf1f1499052', '10539444294495885880,11835255382125504158,7805278224136830340,10565017852653644273,17136457921820408475,15856661223205703565,5718295910879029687,3114025675555949695,7921658050180167840,9433107693532196856,13940724942658998842'],
        ];
    }

    #[DataProvider('keys')]
    public function test_it_matches_cpython(string $k0, string $k1, string $expected)
    {
        $dir = sys_get_temp_dir().'/gazlang_siphash_'.getmypid();
        @mkdir($dir);
        try {
            $root = dirname(__DIR__);
            exec(sprintf('cc -std=c11 -D_DEFAULT_SOURCE -I %s -o %s %s %s 2>&1', escapeshellarg($root.'/vm'), escapeshellarg($dir.'/check'), escapeshellarg($root.'/tests/fixtures/siphash_check.c'), escapeshellarg($root.'/vm/siphash.c')), $output, $code);
            $this->assertSame(0, $code, implode("\n", $output));

            $lengths = implode(' ', self::LENGTHS);
            $this->assertSame($expected, trim((string) shell_exec(escapeshellarg($dir.'/check')." {$k0} {$k1} {$lengths}")));
        } finally {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }
}
