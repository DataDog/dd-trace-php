<?php
// Child script for php2shell_mitigation_sapi_off.phpt: prints the SAPI and the
// PHP2Shell MINFO rows of this process on one line:
//   MINFO sapi=S state=V hooks=N treat_data=V interrupt=V gc_mem_caches=V
// hooks counts the hook rows; a missing row is reported as none.
require_once __DIR__ . '/php2shell_cgi.php';
$minfo = p2s_minfo();
$hooks = 0;
foreach ($minfo as $name => $value) {
    if (strpos($name, 'PHP2Shell') === 0 && substr($name, -5) === ' hook') {
        $hooks++;
    }
}
$row = function ($needle) use ($minfo) {
    $v = p2s_hook_row($minfo, $needle);
    return $v === null ? 'none' : str_replace(' ', '_', $v);
};
echo "MINFO sapi=", PHP_SAPI,
    " state=", isset($minfo[P2S_ROW_STATE]) ? $minfo[P2S_ROW_STATE] : 'none',
    " hooks=", $hooks,
    " treat_data=", $row('treat_data'),
    " interrupt=", $row('zend_interrupt_function'),
    " gc_mem_caches=", $row('gc_mem_caches'),
    "\n";
