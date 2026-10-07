<?php
function line_hook_preloaded($n) {
    $x = $n + 1;
    return $x;
}
class LineHookPreloaded {
    public static function method($n) {
        $x = $n + 1;
        return $x;
    }
}
