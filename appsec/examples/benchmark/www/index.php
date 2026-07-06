<?php
$handler = static function () { echo "ok"; };
while (frankenphp_handle_request($handler)) {}
