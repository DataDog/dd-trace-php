--TEST--
A hook joined to an in-flight call reports the same id install_hook() returned
--DESCRIPTION--
install_hook() joined the calls already running before it had assigned the definition's id, so HookData::$id carried the pre-assignment sentinel instead.
That is not only wrong to read: line-hook ids are negative, so passing such a value back to remove_hook() could delete an unrelated line hook.
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_TRACE_LOG_LEVEL=off
DD_APPSEC_ENABLED=0
--FILE--
<?php

$installed = null;
$seen = [];

function target() {
    global $installed, $seen;
    if ($installed === null) {
        $installed = DDTrace\install_hook('target', null, function (DDTrace\HookData $h) use (&$seen) {
            $seen[] = $h->id;
        });
    }
    return 1;
}

var_dump(target());          // joined mid-call: $h->id must already be the returned id
var_dump(target());          // ordinary entry, for comparison
var_dump($installed > 0);
var_dump(count($seen));
var_dump($seen[0] === $installed, $seen[1] === $installed);

DDTrace\remove_hook($installed);
echo "Done.\n";
?>
--EXPECT--
int(1)
int(1)
bool(true)
int(2)
bool(true)
bool(true)
Done.
