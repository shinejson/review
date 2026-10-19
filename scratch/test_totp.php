<?php
function tfa_base32_decode($b32) {
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32));
    if ($b32 === '') return '';
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bin = '';
    for ($i = 0; $i < strlen($b32); $i++) {
        $pos = strpos($alphabet, $b32[$i]);
        if ($pos === false) continue;
        $bin .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    for ($i = 0; $i + 8 <= strlen($bin); $i += 8) {
        $bytes .= chr(bindec(substr($bin, $i, 8)));
    }
    return $bytes;
}

function tfa_base32_encode($data) {
    if ($data === '') return '';
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bin = '';
    for ($i = 0; $i < strlen($data); $i++) {
        $bin .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }
    $rem = strlen($bin) % 5;
    if ($rem > 0) {
        $bin .= str_repeat('0', 5 - $rem);
    }
    $b32 = '';
    for ($i = 0; $i < strlen($bin); $i += 5) {
        $val = bindec(substr($bin, $i, 5));
        $b32 .= $alphabet[$val];
    }
    return $b32;
}

function tfa_compute_totp($secret, $timeSlice) {
    $key = tfa_base32_decode($secret);
    $time = pack('N*', 0) . pack('N*', $timeSlice);
    $hash = hash_hmac('sha1', $time, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $val = (((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF));
    return str_pad((string)($val % 1000000), 6, '0', STR_PAD_LEFT);
}

// RFC 6238 Appendix B test vector for SHA1:
// Seed: "12345678901234567890" (20 bytes ASCII)
// Base32: GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ
// Time = 59 (T = floor(59/30) = 1) -> 287082
// Time = 1111111109 (T = 37037036) -> 081804
// Time = 1111111111 (T = 37037037) -> 050471
// Time = 1234567890 (T = 41152263) -> 005924
// Time = 2000000000 (T = 66666666) -> 279037

$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$tests = [
    1 => '287082',
    37037036 => '081804',
    37037037 => '050471',
    41152263 => '005924',
    66666666 => '279037',
];

$allPass = true;
foreach ($tests as $t => $expected) {
    $actual = tfa_compute_totp($secret, $t);
    $ok = ($actual === $expected);
    echo "T=$t: expected $expected, got $actual: " . ($ok ? 'PASS' : 'FAIL') . PHP_EOL;
    if (!$ok) $allPass = false;
}

// Test encode/decode round trip
$random = random_bytes(20);
$enc = tfa_base32_encode($random);
$dec = tfa_base32_decode($enc);
$rtPass = ($random === $dec);
echo "Base32 Roundtrip: " . ($rtPass ? 'PASS' : 'FAIL') . PHP_EOL;

if ($allPass && $rtPass) {
    echo "ALL RFC 6238 TOTP TESTS PASSED!\n";
}
