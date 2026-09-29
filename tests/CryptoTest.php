<?php

namespace GazLang\Tests;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The cryptography builtins (vm/crypto.c) and lib/crypto.gaz against implementations that share
 * no code with them: PHP's hash extension, libsodium and libargon2, Python's hashlib and OpenSSL's
 * command line. tests/gaz/lib/crypto_test.gaz checks the specifications' own test vectors.
 *
 * Inputs come from a small linear congruential generator that both sides write out, so a program
 * prints only digests and stays small enough to be recorded and run under the sanitizers.
 */
class CryptoTest extends GazLangTestCase
{
    /**
     * Lengths either side of SHA-256's padding (55, 56) and blocks (64, 128), and of BLAKE2b's (128)
     */
    private const LENGTHS = [0, 1, 31, 32, 33, 55, 56, 57, 63, 64, 65, 111, 112, 119, 120, 127, 128, 129, 200, 1000];

    /**
     * Key lengths either side of HMAC's block, past which a key is hashed first
     */
    private const KEY_LENGTHS = [0, 1, 32, 63, 64, 65, 131];

    /**
     * The generator in GazLang, and a program's start: noise($n) is $n bytes
     */
    private const NOISE = <<<'GAZ'
        include "std/crypto.gaz";
        @state = 1;
        fn noise($n) {
            $s = "";
            for ($i = 0; $i < $n; $i++) {
                @state = (@state * 1103515245 + 12345) % 2147483648;
                $s ..= chr((@state >> 16) & 255);
            }
            return $s;
        }

        GAZ;

    private int $state = 1;

    /**
     * The same bytes noise($n) gives, from the same state
     */
    private function noise(int $n): string
    {
        $s = '';
        for ($i = 0; $i < $n; $i++) {
            $this->state = ($this->state * 1103515245 + 12345) % 2147483648;
            $s .= chr(($this->state >> 16) & 255);
        }

        return $s;
    }

    /**
     * What a program that starts with the generator prints, a line each
     *
     * @return list<string>
     */
    private function lines(string $code): array
    {
        return explode("\n", rtrim($this->executeCode(self::NOISE.$code), "\n"));
    }

    public function test_sha256_hmac_and_pbkdf2_match_php_s_hash_extension()
    {
        $code = 'foreach (['.implode(', ', self::LENGTHS).'] as $n) {'
            .' $data = noise($n); echo crypto::hex(sha256($data));'
            .' foreach (['.implode(', ', self::KEY_LENGTHS).'] as $k) { echo crypto::hex(hmac_sha256($data, noise($k))); } }'
            .' foreach ([[1, 1], [1, 32], [2, 33], [3, 64], [1000, 65], [7, 100], [2, 31]] as [$iterations, $length]) {'
            .' foreach ([0, 1, 64, 65, 131] as $p) { $password = noise($p); $salt = noise(($p * 7) % 61);'
            .' echo crypto::hex(pbkdf2_sha256($password, $salt, $iterations, $length)); } }';
        $expected = [];
        foreach (self::LENGTHS as $n) {
            $data = $this->noise($n);
            $expected[] = hash('sha256', $data);
            foreach (self::KEY_LENGTHS as $k) {
                $expected[] = hash_hmac('sha256', $data, $this->noise($k));
            }
        }
        foreach ([[1, 1], [1, 32], [2, 33], [3, 64], [1000, 65], [7, 100], [2, 31]] as [$iterations, $length]) {
            foreach ([0, 1, 64, 65, 131] as $p) {
                $password = $this->noise($p);
                $salt = $this->noise(($p * 7) % 61);
                $expected[] = bin2hex(hash_pbkdf2('sha256', $password, $salt, $iterations, $length, true));
            }
        }
        $this->assertSame($expected, $this->lines($code));
    }

    public function test_scrypt_matches_python_s_hashlib()
    {
        $cases = [];
        foreach ([[2, 1, 1, 1], [4, 1, 2, 32], [8, 2, 1, 64], [16, 3, 3, 65], [64, 8, 1, 33], [256, 1, 1, 100], [32, 5, 2, 7], [1024, 1, 1, 64], [2, 16, 1, 32]] as $i => [$n, $r, $p, $length]) {
            $cases[] = ['password' => bin2hex($this->noise($i * 9)), 'salt' => bin2hex($this->noise(($i * 5) % 17)), 'n' => $n, 'r' => $r, 'p' => $p, 'length' => $length];
        }
        $python = 'import hashlib, json, sys'."\n"
            .'for c in json.load(sys.stdin): print(hashlib.scrypt(bytes.fromhex(c["password"]), salt=bytes.fromhex(c["salt"]), n=c["n"], r=c["r"], p=c["p"], dklen=c["length"]).hex())';
        $expected = $this->python($python, json_encode($cases, JSON_THROW_ON_ERROR));
        $code = 'foreach (['.implode(', ', array_map(fn ($c) => "[{$c['n']}, {$c['r']}, {$c['p']}, {$c['length']}]", $cases)).'] as $i => [$n, $r, $p, $length]) {'
            .' $password = noise($i * 9); $salt = noise(($i * 5) % 17); echo crypto::hex(scrypt($password, $salt, $n, $r, $p, $length)); }';
        $this->assertSame($expected, $this->lines($code));
    }

    public function test_argon2id_matches_libsodium()
    {
        // libsodium's Argon2id has one lane and a 16-byte salt, takes a password of a byte or
        // more and gives 16 bytes or more
        $cases = [[1, 8, 16], [1, 9, 32], [2, 16, 63], [3, 33, 64], [1, 64, 65], [2, 40, 96], [1, 20, 97], [1, 600, 128], [2, 12, 200], [1, 8, 1024]];
        $expected = [];
        foreach ($cases as $i => [$passes, $memory, $length]) {
            $password = $this->noise($i * 11 + 1);
            $salt = $this->noise(16);
            $expected[] = bin2hex(sodium_crypto_pwhash($length, $password, $salt, $passes, $memory * 1024, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13));
        }
        $code = 'foreach (['.implode(', ', array_map(fn ($c) => '['.implode(', ', $c).']', $cases)).'] as $i => [$passes, $memory, $length]) {'
            .' $password = noise($i * 11 + 1); $salt = noise(16); echo crypto::hex(argon2id($password, $salt, $passes, $memory, 1, $length)); }';
        $this->assertSame($expected, $this->lines($code));
    }

    public function test_argon2id_matches_openssl_with_lanes_secrets_and_data()
    {
        exec('openssl kdf -keylen 4 -kdfopt pass:x -kdfopt salt:saltsalt -kdfopt iter:1 -kdfopt memcost:8 -kdfopt lanes:1 ARGON2ID 2>/dev/null', $out, $status);
        if ($status !== 0) {
            $this->markTestSkipped('needs an openssl command with Argon2id (OpenSSL 3.2 or later)');
        }
        // passes, memory, lanes, length, and the lengths of the password, salt, secret and data
        $cases = [[1, 8, 1, 4, 0, 8, 0, 0], [2, 26, 3, 5, 3, 9, 1, 1], [1, 37, 4, 64, 64, 31, 8, 12], [3, 40, 5, 65, 65, 64, 32, 0],
            [1, 700, 2, 32, 10, 16, 0, 40], [2, 16, 2, 129, 128, 16, 64, 64], [1, 1030, 1, 33, 5, 12, 16, 3]];
        $expected = [];
        foreach ($cases as [$passes, $memory, $lanes, $length, $p, $s, $k, $x]) {
            [$password, $salt, $secret, $data] = [$this->noise($p), $this->noise($s), $this->noise($k), $this->noise($x)];
            $options = ['-keylen', $length, '-kdfopt', 'hexsalt:'.bin2hex($salt), '-kdfopt', "iter:{$passes}", '-kdfopt', "memcost:{$memory}", '-kdfopt', "lanes:{$lanes}"];
            // OpenSSL takes no empty values
            foreach (['hexpass' => $password, 'hexsecret' => $secret, 'hexad' => $data] as $name => $value) {
                if ($value !== '') {
                    array_push($options, '-kdfopt', "{$name}:".bin2hex($value));
                }
            }
            $tag = (string) shell_exec(implode(' ', array_map('escapeshellarg', ['openssl', 'kdf', ...$options, 'ARGON2ID'])));
            $this->assertMatchesRegularExpression('/^[0-9A-F:]+$/', trim($tag));
            $expected[] = strtolower(str_replace(':', '', trim($tag)));
        }
        $code = 'foreach (['.implode(', ', array_map(fn ($c) => '['.implode(', ', $c).']', $cases)).'] as [$passes, $memory, $lanes, $length, $p, $s, $k, $x]) {'
            .' $password = noise($p); $salt = noise($s); $secret = noise($k); $data = noise($x);'
            .' echo crypto::hex(argon2id($password, $salt, $passes, $memory, $lanes, $length, $secret, $data)); }';
        $this->assertSame($expected, $this->lines($code));
    }

    public function test_argon2id_hashes_interoperate_with_php_s_password_hash()
    {
        // Salted at random each run, so not recorded: run as it is
        foreach ([[1024, 1, 1], [2048, 2, 2], [1500, 3, 3]] as [$memory, $passes, $lanes]) {
            $theirs = password_hash('correct horse', PASSWORD_ARGON2ID, ['memory_cost' => $memory, 'time_cost' => $passes, 'threads' => $lanes]);
            $code = 'include "std/crypto.gaz"; $theirs = '.self::quote($theirs).';'
                .' echo [crypto::verify_password("correct horse", $theirs), crypto::verify_password("correct horsE", $theirs)];'
                ." print(crypto::hash_password(\"correct horse\", {\"m\" => {$memory}, \"t\" => {$passes}, \"p\" => {$lanes}}));";
            [$verdicts, $ours] = explode("\n", self::succeed([], $code));
            $this->assertSame('[true, false]', $verdicts, $theirs);
            $this->assertStringStartsWith("\$argon2id\$v=19\$m={$memory},t={$passes},p={$lanes}\$", $ours);
            $this->assertTrue(password_verify('correct horse', $ours), $ours);
            $this->assertFalse(password_verify('correct horsE', $ours), $ours);
        }
    }

    public function test_scrypt_and_pbkdf2_hashes_are_what_python_and_php_derive_from_their_parameters()
    {
        // Salted at random each run, so not recorded: each parameter away from its default, so a
        // hash made without one no longer matches what its stored string says
        $code = 'include "std/crypto.gaz";'
            .' print(crypto::hash_password("correct horse", {"algorithm" => "scrypt", "ln" => 5, "r" => 3, "p" => 2}) .. "\\n");'
            .' print(crypto::hash_password("correct horse", {"algorithm" => "scrypt", "ln" => 3, "r" => 1, "p" => 3}) .. "\\n");'
            .' print(crypto::hash_password("correct horse", {"algorithm" => "pbkdf2-sha256", "i" => 1234}) .. "\\n");'
            .' print(crypto::hash_password("correct horse", {"algorithm" => "pbkdf2-sha256", "i" => 1}));';
        [$scrypt1, $scrypt2, $pbkdf2a, $pbkdf2b] = explode("\n", self::succeed([], $code));
        $scrypts = [];
        foreach ([$scrypt1, $scrypt2] as $stored) {
            $this->assertSame(1, preg_match('#^\$scrypt\$ln=(\d+),r=(\d+),p=(\d+)\$([^$]+)\$([^$]+)$#', $stored, $m), $stored);
            $scrypts[] = ['password' => bin2hex('correct horse'), 'salt' => bin2hex(self::unpadded($m[4])), 'n' => 2 ** (int) $m[1], 'r' => (int) $m[2], 'p' => (int) $m[3], 'length' => 32, 'hash' => bin2hex(self::unpadded($m[5]))];
        }
        $this->assertSame([[32, 3, 2], [8, 1, 3]], array_map(fn ($c) => [$c['n'], $c['r'], $c['p']], $scrypts));
        $python = 'import hashlib, json, sys'."\n"
            .'for c in json.load(sys.stdin): print(hashlib.scrypt(bytes.fromhex(c["password"]), salt=bytes.fromhex(c["salt"]), n=c["n"], r=c["r"], p=c["p"], dklen=c["length"]).hex())';
        $this->assertSame(array_column($scrypts, 'hash'), $this->python($python, json_encode($scrypts, JSON_THROW_ON_ERROR)));
        foreach ([[$pbkdf2a, 1234], [$pbkdf2b, 1]] as [$stored, $iterations]) {
            $this->assertSame(1, preg_match('#^\$pbkdf2-sha256\$i=(\d+)\$([^$]+)\$([^$]+)$#', $stored, $m), $stored);
            $this->assertSame($iterations, (int) $m[1]);
            $this->assertSame(self::unpadded($m[3]), hash_pbkdf2('sha256', 'correct horse', self::unpadded($m[2]), $iterations, 32, true));
        }
    }

    /**
     * The bytes of base64 without its padding, as the stored strings write them
     */
    private static function unpadded(string $text): string
    {
        return (string) base64_decode($text.str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    }

    public function test_hash_password_defaults_to_argon2id_with_rfc_9106_s_second_recommendation()
    {
        $stored = self::succeed([], 'include "std/crypto.gaz"; $s = crypto::hash_password("pw"); echo crypto::verify_password("pw", $s); print($s);');
        $this->assertMatchesRegularExpression('#^true\n\$argon2id\$v=19\$m=65536,t=3,p=4\$[A-Za-z0-9+/]{22}\$[A-Za-z0-9+/]{43}$#', $stored);
        $this->assertTrue(password_verify('pw', substr($stored, 5)));
    }

    public function test_scrypt_s_largest_rfc_7914_vector()
    {
        // 1 GiB of memory, too much for the sanitized build: run as it is, not recorded
        $this->assertSame(
            "2101cb9b6a511aaeaddbbe09cf70f881ec568d574a2ffd4dabe5ee9820adaa478e56fd8f4ba5d09ffa1c6d927c40f4c337304049e8a952fbcbf45c6fa77a41a4\n",
            self::succeed([], 'include "std/crypto.gaz"; echo crypto::hex(scrypt("pleaseletmein", "SodiumChloride", 1048576, 8, 1, 64));')
        );
    }

    #[DataProvider('badArguments')]
    public function test_the_builtins_check_their_arguments(string $code, string $message)
    {
        $this->expectExceptionMessage($message);
        $this->executeCode($code);
    }

    public static function badArguments(): array
    {
        return [
            'random_bytes of a float' => ['random_bytes(1.0);', 'random_bytes() expects int, got float'],
            'random_bytes of less than none' => ['random_bytes(-1);', 'random_bytes() expects a length from 0 to 1048576, got -1'],
            'random_bytes of too many' => ['random_bytes(1048577);', 'random_bytes() expects a length from 0 to 1048576, got 1048577'],
            'sha256 of a number' => ['sha256(5);', 'sha256() expects string, got int'],
            'hmac_sha256 without a string key' => ['hmac_sha256("data", null);', 'hmac_sha256() expects string, got null'],
            'pbkdf2_sha256 of a list' => ['pbkdf2_sha256(["pw"], "salt", 1, 32);', 'pbkdf2_sha256() expects string, got list'],
            'pbkdf2_sha256 with a float count' => ['pbkdf2_sha256("pw", "salt", 1.0, 32);', 'pbkdf2_sha256() expects int, got float'],
            'pbkdf2_sha256 with no iterations' => ['pbkdf2_sha256("pw", "salt", 0, 32);', 'pbkdf2_sha256() expects 1 to 4294967295 iterations, got 0'],
            'pbkdf2_sha256 with too many' => ['pbkdf2_sha256("pw", "salt", 4294967296, 32);', 'pbkdf2_sha256() expects 1 to 4294967295 iterations, got 4294967296'],
            'pbkdf2_sha256 of nothing' => ['pbkdf2_sha256("pw", "salt", 1, 0);', 'pbkdf2_sha256() expects a length from 1 to 1048576, got 0'],
            'pbkdf2_sha256 of too much' => ['pbkdf2_sha256("pw", "salt", 1, 1048577);', 'pbkdf2_sha256() expects a length from 1 to 1048576, got 1048577'],
            'scrypt with a string cost' => ['scrypt("pw", "salt", "16", 1, 1, 32);', 'scrypt() expects int, got string'],
            'scrypt with a cost of 1' => ['scrypt("pw", "salt", 1, 1, 1, 32);', 'scrypt() expects a cost that is a power of 2 greater than 1, got 1'],
            'scrypt with a cost not a power of 2' => ['scrypt("pw", "salt", 48, 1, 1, 32);', 'scrypt() expects a cost that is a power of 2 greater than 1, got 48'],
            'scrypt with a negative cost' => ['scrypt("pw", "salt", -9223372036854775807 - 1, 1, 1, 32);', 'scrypt() expects a cost that is a power of 2 greater than 1, got -9223372036854775808'],
            'scrypt with no block size' => ['scrypt("pw", "salt", 16, 0, 1, 32);', 'scrypt() expects a block size of 1 or more, got 0'],
            'scrypt with no parallelism' => ['scrypt("pw", "salt", 16, 1, 0, 32);', 'scrypt() expects a parallelism of 1 or more, got 0'],
            'scrypt with a cost past RFC 7914 s limit' => ['scrypt("pw", "salt", 65536, 1, 1, 32);', 'scrypt() expects a cost below 2 ** (16 * block size), got 65536 with a block size of 1'],
            'scrypt asking for terabytes' => ['scrypt("pw", "salt", 1099511627776, 8, 1, 32);', 'scrypt() would use more than 4294967296 bytes of memory: 128 * block size * (cost + parallelism + 2)'],
            'scrypt with a huge block size' => ['scrypt("pw", "salt", 2, 9223372036854775807, 1, 32);', 'scrypt() would use more than 4294967296 bytes of memory'],
            'scrypt with a huge parallelism' => ['scrypt("pw", "salt", 2, 1, 9223372036854775807, 32);', 'scrypt() would use more than 4294967296 bytes of memory'],
            'scrypt of nothing' => ['scrypt("pw", "salt", 2, 1, 1, 0);', 'scrypt() expects a length from 1 to 1048576, got 0'],
            'argon2id with a short salt' => ['argon2id("pw", "1234567", 1, 8, 1, 32);', 'argon2id() expects a salt of 8 bytes or more, got 7'],
            'argon2id with no passes' => ['argon2id("pw", "saltsalt", 0, 8, 1, 32);', 'argon2id() expects 1 to 4294967295 passes, got 0'],
            'argon2id with no lanes' => ['argon2id("pw", "saltsalt", 1, 8, 0, 32);', 'argon2id() expects 1 to 524288 lanes, got 0'],
            'argon2id with more lanes than 4 GiB can hold' => ['argon2id("pw", "saltsalt", 1, 8, 524289, 32);', 'argon2id() expects 1 to 524288 lanes, got 524289'],
            'argon2id with RFC 9106 s most lanes' => ['argon2id("pw", "saltsalt", 1, 8, 16777215, 32);', 'argon2id() expects 1 to 524288 lanes, got 16777215'],
            'argon2id with the most lanes and too little memory' => ['argon2id("pw", "saltsalt", 1, 4194303, 524288, 32);', 'argon2id() expects a memory from 4194304 KiB (8 per lane) to 4194304 KiB, got 4194303'],
            'scrypt with just too much memory' => ['scrypt("pw", "salt", 4194304, 8, 1, 32);', 'scrypt() would use more than 4294967296 bytes of memory: 128 * block size * (cost + parallelism + 2)'],
            'argon2id with too little memory for its lanes' => ['argon2id("pw", "saltsalt", 1, 15, 2, 32);', 'argon2id() expects a memory from 16 KiB (8 per lane) to 4194304 KiB, got 15'],
            'argon2id asking for terabytes' => ['argon2id("pw", "saltsalt", 1, 1073741824, 1, 32);', 'argon2id() expects a memory from 8 KiB (8 per lane) to 4194304 KiB, got 1073741824'],
            'argon2id with a short tag' => ['argon2id("pw", "saltsalt", 1, 8, 1, 3);', 'argon2id() expects a length from 4 to 1048576, got 3'],
            'argon2id with a secret that is not a string' => ['argon2id("pw", "saltsalt", 1, 8, 1, 32, 5);', 'argon2id() expects string, got int'],
            'argon2id with data that is not a string' => ['argon2id("pw", "saltsalt", 1, 8, 1, 32, "", []);', 'argon2id() expects string, got list'],
            'argon2id with a passes that is not an int' => ['argon2id("pw", "saltsalt", null, 8, 1, 32);', 'argon2id() expects int, got null'],
        ];
    }

    public function test_errors_are_catchable()
    {
        $this->assertSame(
            "argon2id() expects a salt of 8 bytes or more, got 0\nscrypt() expects a cost that is a power of 2 greater than 1, got 3\n",
            $this->executeCode('foreach ([() -> argon2id("pw", "", 1, 8, 1, 32), () -> scrypt("pw", "", 3, 1, 1, 32)] as $f) { try { $f(); } catch (Error $e) { echo $e.message; } }')
        );
    }

    /**
     * What python3 prints for a script given standard input, a line each; the test is skipped
     * without it
     *
     * @return list<string>
     */
    private function python(string $script, string $stdin): array
    {
        $process = proc_open(['python3', '-c', $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if ($process === false) {
            $this->markTestSkipped('needs python3');
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        if (proc_close($process) !== 0) {
            $this->markTestSkipped("needs python3 with hashlib.scrypt: {$err}");
        }

        return explode("\n", rtrim($out, "\n"));
    }
}
