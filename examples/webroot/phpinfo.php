<?php
phpinfo();

if (function_exists('dd_trace_internal_fn')) {
	dd_trace_internal_fn('synchronous_flush');
}
