--TEST--
Line hooks resolve inside enum methods and static enum methods
--SKIPIF--
<?php if (PHP_VERSION_ID < 80100) die('skip requires PHP 8.1'); ?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

enum Suit: string {
    case Hearts = 'H';
    case Spades = 'S';

    public function label(): string {
        return 'label:' . $this->value;     // 8
    }
    public static function count(): int {
        return count(self::cases());        // 11
    }
}

$log = [];
$p = function (DDTrace\LineHookData $h) use (&$log) { $log[] = $h->line; };
$a = DDTrace\install_line_hook(__FILE__, 8, $p);
$b = DDTrace\install_line_hook(__FILE__, 11, $p);

var_dump(Suit::Hearts->label());
var_dump(Suit::Spades->label());
var_dump(Suit::count());
echo implode(',', $log), "\n";
DDTrace\remove_hook($a);
DDTrace\remove_hook($b);
echo "Done.\n";
?>
--EXPECT--
string(7) "label:H"
string(7) "label:S"
int(2)
8,8,11
Done.
