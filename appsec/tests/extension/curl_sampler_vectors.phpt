--TEST--
Downstream sampler: RFC-1062 Appendix A vectors, rate 1 and rate 0
--DESCRIPTION--
The counter is set through the testing setter, so that the known vectors can
be checked despite the random start. The decision ticks the counter first, so
a counter set to N-1 decides count N.
--INI--
datadog.appsec.enabled=1
--ENV--
DD_API_SECURITY_MAX_DOWNSTREAM_REQUEST_BODY_ANALYSIS=1000
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown,should_send_downstream_bodies,downstream_sampler_set_counter,downstream_sampler_get_counter};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], new ArrayObject(), new ArrayObject()]))
]);

function rate($r) {
    ini_set('datadog.api_security_downstream_body_analysis_sample_rate', (string)$r);
}

var_dump(rinit());

rate(0.5);
downstream_sampler_set_counter(0);
$s = '';
for ($i = 1; $i <= 16; $i++) {
    $s .= should_send_downstream_bodies() ? '1' : '0';
}
var_dump($s);
var_dump(downstream_sampler_get_counter());

foreach ([0.5, 0.25, 0.1, 0.01] as $r) {
    rate($r);
    downstream_sampler_set_counter(0);
    $n = 0;
    do {
        $n++;
    } while (!should_send_downstream_bodies() && $n < 1000);
    echo "first sampled at rate $r: $n\n";
}

rate(1);
downstream_sampler_set_counter(0);
$s = '';
for ($i = 0; $i < 20; $i++) {
    $s .= should_send_downstream_bodies() ? '1' : '0';
}
var_dump($s);

rate(0);
$s = '';
for ($i = 0; $i < 20; $i++) {
    $s .= should_send_downstream_bodies() ? '1' : '0';
}
var_dump($s);

var_dump(rshutdown());
?>
--EXPECT--
bool(true)
string(16) "0101101001011010"
int(16)
first sampled at rate 0.5: 2
first sampled at rate 0.25: 2
first sampled at rate 0.1: 5
first sampled at rate 0.01: 89
string(20) "11111111111111111111"
string(20) "00000000000000000000"
bool(true)
