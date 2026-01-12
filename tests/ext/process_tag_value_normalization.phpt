--TEST--
Process tag value normalization
--FILE--
<?php
$test_cases = [
    // Basic cases
    ['#test_starting_hash', 'test_starting_hash', 'Remove leading hash'],
    ['TestCAPSandSuch', 'testcapsandsuch', 'Lowercase conversion'],
    ['Test Conversion Of Weird !@#$%^&**() Characters', 'test_conversion_of_weird_characters', 'Special characters to underscores'],
    ['$#weird_starting', 'weird_starting', 'Remove leading special chars'],
    ['allowed:c0l0ns', 'allowed_c0l0ns', 'Colons to underscores'],
    ['1love', '1love', 'Leading digit allowed'],
    ['/love2', '/love2', 'Leading slash allowed'],
    ['ünicöde', 'ünicöde', 'Unicode preserved'],
    ['ünicöde:metäl', 'ünicöde_metäl', 'Unicode with colon'],
    ['Data🐨dog🐶 繋がっ⛰てて', 'data_dog_繋がっ_てて', 'Emoji and CJK characters'],
    [' spaces   ', 'spaces', 'Trim spaces'],
    [' #hashtag!@#spaces #__<>#  ', 'hashtag_spaces', 'Complex special chars and spaces'],
    [':testing', 'testing', 'Leading colon removed'],
    ['_foo', 'foo', 'Leading underscore removed'],
    [':::test', 'test', 'Multiple leading colons'],
    ['contiguous_____underscores', 'contiguous_underscores', 'Collapse underscores'],
    ['foo_', 'foo', 'Trailing underscore removed'],
    ["\u{017F}odd_\u{017F}case\u{017F}", "\u{017F}odd_\u{017F}case\u{017F}", 'Latin small letter long s (U+017F)'],
    ['', '', 'Empty string'],
    [' ', '', 'Only spaces'],
    ['ok', 'ok', 'Simple valid string'],
    ['™Ö™Ö™™Ö™', 'ö_ö_ö', 'Trademark symbols and umlauts'],
    ['AlsO:ök', 'also_ök', 'Mixed case with colon and umlaut'],
    [':still_ok', 'still_ok', 'Leading colon with underscore'],
    ['___trim', 'trim', 'Multiple leading underscores'],
    ['12.:trim@', '12._trim', 'Digit, dot, colon, at sign'],
    ['12.:trim@@', '12._trim', 'Multiple at signs collapsed'],
    ['fun:ky__tag/1', 'fun_ky_tag/1', 'Colon, underscores, slash'],
    ['fun:ky@tag/2', 'fun_ky_tag/2', 'Colon, at sign, slash'],
    ['fun:ky@@@tag/3', 'fun_ky_tag/3', 'Multiple at signs'],
    ['tag:1/2.3', 'tag_1/2.3', 'Slash and dot preserved'],
    ['---fun:k####y_ta@#g/1_@@#', '---fun_k_y_ta_g/1', 'Complex mix with trailing underscore'],
    ['AlsO:œ#@ö))œk', 'also_œ_ö_œk', 'Mixed case with special Latin chars'],
    ["test\x99\x8faaa", 'test_aaa', 'Invalid UTF-8 with trailing valid chars'],
    ["test\x99\x8f", 'test', 'Invalid UTF-8 at end'],
    [str_repeat('a', 888), str_repeat('a', 100), 'Truncate at 100 chars'],
    ['a' . str_repeat('🐶', 799) . 'b', 'a', 'Multi-byte emoji truncation'],
    ['a' . "\u{FFFD}", 'a', 'Replacement character trailing'],
    ['a' . "\u{FFFD}" . "\u{FFFD}", 'a', 'Multiple replacement characters'],
    ['a' . "\u{FFFD}" . "\u{FFFD}" . 'b', 'a_b', 'Replacement characters between letters'],
    ['A' . str_repeat('0', 97) . ' ' . str_repeat('0', 11), 'a' . str_repeat('0', 97) . '_0', 'Truncate at 100 char limit (space at boundary is trimmed)'],
];

$passed = 0;
$failed = 0;
$failed_tests = [];

foreach ($test_cases as $idx => $test) {
    list($input, $expected, $description) = $test;

    $result = \DDTrace\Testing\normalize_tag_value($input);

    if ($result === $expected) {
        $passed++;
        echo sprintf("✓ Test %3d PASS: %s\n", $idx + 1, $description);
    } else {
        $failed++;
        $failed_tests[] = [
            'num' => $idx + 1,
            'description' => $description,
            'input' => $input,
            'expected' => $expected,
            'got' => $result
        ];
        echo sprintf("✗ Test %3d FAIL: %s\n", $idx + 1, $description);
    }
}

if ($failed > 0) {
    foreach ($failed_tests as $test) {
        echo sprintf("\nTest %d: %s\n", $test['num'], $test['description']);
        echo "  Input:    " . var_export($test['input'], true) . "\n";
        echo "  Expected: " . var_export($test['expected'], true) . "\n";
        echo "  Got:      " . var_export($test['got'], true) . "\n";
    }
    exit(1);
}
?>
--EXPECT--
✓ Test   1 PASS: Remove leading hash
✓ Test   2 PASS: Lowercase conversion
✓ Test   3 PASS: Special characters to underscores
✓ Test   4 PASS: Remove leading special chars
✓ Test   5 PASS: Colons to underscores
✓ Test   6 PASS: Leading digit allowed
✓ Test   7 PASS: Leading slash allowed
✓ Test   8 PASS: Unicode preserved
✓ Test   9 PASS: Unicode with colon
✓ Test  10 PASS: Emoji and CJK characters
✓ Test  11 PASS: Trim spaces
✓ Test  12 PASS: Complex special chars and spaces
✓ Test  13 PASS: Leading colon removed
✓ Test  14 PASS: Leading underscore removed
✓ Test  15 PASS: Multiple leading colons
✓ Test  16 PASS: Collapse underscores
✓ Test  17 PASS: Trailing underscore removed
✓ Test  18 PASS: Latin small letter long s (U+017F)
✓ Test  19 PASS: Empty string
✓ Test  20 PASS: Only spaces
✓ Test  21 PASS: Simple valid string
✓ Test  22 PASS: Trademark symbols and umlauts
✓ Test  23 PASS: Mixed case with colon and umlaut
✓ Test  24 PASS: Leading colon with underscore
✓ Test  25 PASS: Multiple leading underscores
✓ Test  26 PASS: Digit, dot, colon, at sign
✓ Test  27 PASS: Multiple at signs collapsed
✓ Test  28 PASS: Colon, underscores, slash
✓ Test  29 PASS: Colon, at sign, slash
✓ Test  30 PASS: Multiple at signs
✓ Test  31 PASS: Slash and dot preserved
✓ Test  32 PASS: Complex mix with trailing underscore
✓ Test  33 PASS: Mixed case with special Latin chars
✓ Test  34 PASS: Invalid UTF-8 with trailing valid chars
✓ Test  35 PASS: Invalid UTF-8 at end
✓ Test  36 PASS: Truncate at 100 chars
✓ Test  37 PASS: Multi-byte emoji truncation
✓ Test  38 PASS: Replacement character trailing
✓ Test  39 PASS: Multiple replacement characters
✓ Test  40 PASS: Replacement characters between letters
✓ Test  41 PASS: Truncate at 100 char limit (space at boundary is trimmed)
