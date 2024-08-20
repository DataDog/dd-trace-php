--TEST--
Line breakpoint on interface-hook
--FILE--
<?php

require __DIR__ . '/../dbgpclient.php';

$commands = [
	'feature_set -n resolved_breakpoint -v 1',
	'step_into',
	'breakpoint_set -t line -f ' . __DIR__ . '/line-breakpoint.inc -n 15',
	'breakpoint_set -t line -f ' . __DIR__ . '/line-breakpoint.inc -n 17',
	'run',
	'run',
	'detach',
];

dbgpRunFile(__DIR__ . "/line-breakpoint.inc", $commands, ["zend_extension" => "xdebug-" . phpversion('xdebug'), "datadog.trace.sources_path" => __DIR__ . "/..", "datadog.logs_injection" => 0], ["show-stdout" => true]);

?>
--EXPECTF--
<?xml version="1.0" encoding="iso-8859-1"?>
<init xmlns="urn:debugger_protocol_v1" xmlns:xdebug="https://xdebug.org/dbgp/xdebug" fileuri="file://line-breakpoint.inc" language="PHP" xdebug:language_version="" protocol_version="1.0" appid=""><engine version=""><![CDATA[Xdebug]]></engine><author><![CDATA[Derick Rethans]]></author><url><![CDATA[https://xdebug.org]]></url><copyright><![CDATA[Copyright (c) 2002-2099 by Derick Rethans]]></copyright></init>

-> feature_set -i 1 -n resolved_breakpoint -v 1
<?xml version="1.0" encoding="iso-8859-1"?>
<response xmlns="urn:debugger_protocol_v1" xmlns:xdebug="https://xdebug.org/dbgp/xdebug" command="feature_set" transaction_id="1" status="starting" reason="ok"><error code="3"><message><![CDATA[invalid or missing options]]></message></error></response>

-> step_into -i 2
<?xml version="1.0" encoding="iso-8859-1"?>
<response xmlns="urn:debugger_protocol_v1" xmlns:xdebug="https://xdebug.org/dbgp/xdebug" command="step_into" transaction_id="2" status="break" reason="ok"><xdebug:message filename="file://line-breakpoint.inc" lineno="9"></xdebug:message></response>

-> breakpoint_set -i 3 -t line -f %s/line-breakpoint.inc -n 15
<?xml version="1.0" encoding="iso-8859-1"?>
<response xmlns="urn:debugger_protocol_v1" xmlns:xdebug="https://xdebug.org/dbgp/xdebug" command="breakpoint_set" transaction_id="3" id="{{PID}}0001"></response>

-> breakpoint_set -i 4 -t line -f %s/line-breakpoint.inc -n 17
<?xml version="1.0" encoding="iso-8859-1"?>
<response xmlns="urn:debugger_protocol_v1" xmlns:xdebug="https://xdebug.org/dbgp/xdebug" command="breakpoint_set" transaction_id="4" id="{{PID}}0002"></response>

-> run -i 5
<?xml version="1.0" encoding="iso-8859-1"?>
<response xmlns="urn:debugger_protocol_v1" xmlns:xdebug="https://xdebug.org/dbgp/xdebug" command="run" transaction_id="5" status="break" reason="ok"><xdebug:message filename="file://line-breakpoint.inc" lineno="15"></xdebug:message>%S</response>

-> run -i 6
<?xml version="1.0" encoding="iso-8859-1"?>
<response xmlns="urn:debugger_protocol_v1" xmlns:xdebug="https://xdebug.org/dbgp/xdebug" command="run" transaction_id="6" status="break" reason="ok"><xdebug:message filename="file://line-breakpoint.inc" lineno="17"></xdebug:message>%S</response>

-> detach -i 7
<?xml version="1.0" encoding="iso-8859-1"?>
<response xmlns="urn:debugger_protocol_v1" xmlns:xdebug="https://xdebug.org/dbgp/xdebug" command="detach" transaction_id="7" status="stopping" reason="ok"></response>

pre-hook
hey
post-hook
