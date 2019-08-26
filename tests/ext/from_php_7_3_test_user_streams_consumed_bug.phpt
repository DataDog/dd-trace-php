--TEST--
Testing user filter on streams
--SKIPIF--
<?php
$version = explode('.', PHP_VERSION);
define('PHP_VERSION_ID', ($version[0] * 10000 + $version[1] * 100 + $version[2]));

if ($version[0] < 7)
    print "This bug was not fixed in PHP 5.x, resulting in false positive detection in 5.x tests";
?>
--FILE--
<?php
class Intercept extends php_user_filter
{
    public static $cache = '';
    public function filter($in, $out, &$consumed, $closing)
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            self::$cache .= $bucket->data;
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}

$out = fwrite(STDOUT, "Hello\n");
var_dump($out);

stream_filter_register("intercept_filter", "Intercept");
stream_filter_append(STDOUT, "intercept_filter");

$out = fwrite(STDOUT, "Goodbye\n");
var_dump($out);
?>
--EXPECT--
Hello
int(6)
Goodbye
int(8)
