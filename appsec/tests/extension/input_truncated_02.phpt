--TEST--
Arrays with more than 256 entries are truncated
--INI--
datadog.appsec.enabled=1
display_errors=1
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--COOKIE--
d[]=1; d[]=2; d[]=3; d[]=4; d[]=5; d[]=6; d[]=7; d[]=8; d[]=9; d[]=10; d[]=11; d[]=12; d[]=13; d[]=14; d[]=15; d[]=16; d[]=17; d[]=18; d[]=19; d[]=20; d[]=21; d[]=22; d[]=23; d[]=24; d[]=25; d[]=26; d[]=27; d[]=28; d[]=29; d[]=30; d[]=31; d[]=32; d[]=33; d[]=34; d[]=35; d[]=36; d[]=37; d[]=38; d[]=39; d[]=40; d[]=41; d[]=42; d[]=43; d[]=44; d[]=45; d[]=46; d[]=47; d[]=48; d[]=49; d[]=50; d[]=51; d[]=52; d[]=53; d[]=54; d[]=55; d[]=56; d[]=57; d[]=58; d[]=59; d[]=60; d[]=61; d[]=62; d[]=63; d[]=64; d[]=65; d[]=66; d[]=67; d[]=68; d[]=69; d[]=70; d[]=71; d[]=72; d[]=73; d[]=74; d[]=75; d[]=76; d[]=77; d[]=78; d[]=79; d[]=80; d[]=81; d[]=82; d[]=83; d[]=84; d[]=85; d[]=86; d[]=87; d[]=88; d[]=89; d[]=90; d[]=91; d[]=92; d[]=93; d[]=94; d[]=95; d[]=96; d[]=97; d[]=98; d[]=99; d[]=100; d[]=101; d[]=102; d[]=103; d[]=104; d[]=105; d[]=106; d[]=107; d[]=108; d[]=109; d[]=110; d[]=111; d[]=112; d[]=113; d[]=114; d[]=115; d[]=116; d[]=117; d[]=118; d[]=119; d[]=120; d[]=121; d[]=122; d[]=123; d[]=124; d[]=125; d[]=126; d[]=127; d[]=128; d[]=129; d[]=130; d[]=131; d[]=132; d[]=133; d[]=134; d[]=135; d[]=136; d[]=137; d[]=138; d[]=139; d[]=140; d[]=141; d[]=142; d[]=143; d[]=144; d[]=145; d[]=146; d[]=147; d[]=148; d[]=149; d[]=150; d[]=151; d[]=152; d[]=153; d[]=154; d[]=155; d[]=156; d[]=157; d[]=158; d[]=159; d[]=160; d[]=161; d[]=162; d[]=163; d[]=164; d[]=165; d[]=166; d[]=167; d[]=168; d[]=169; d[]=170; d[]=171; d[]=172; d[]=173; d[]=174; d[]=175; d[]=176; d[]=177; d[]=178; d[]=179; d[]=180; d[]=181; d[]=182; d[]=183; d[]=184; d[]=185; d[]=186; d[]=187; d[]=188; d[]=189; d[]=190; d[]=191; d[]=192; d[]=193; d[]=194; d[]=195; d[]=196; d[]=197; d[]=198; d[]=199; d[]=200; d[]=201; d[]=202; d[]=203; d[]=204; d[]=205; d[]=206; d[]=207; d[]=208; d[]=209; d[]=210; d[]=211; d[]=212; d[]=213; d[]=214; d[]=215; d[]=216; d[]=217; d[]=218; d[]=219; d[]=220; d[]=221; d[]=222; d[]=223; d[]=224; d[]=225; d[]=226; d[]=227; d[]=228; d[]=229; d[]=230; d[]=231; d[]=232; d[]=233; d[]=234; d[]=235; d[]=236; d[]=237; d[]=238; d[]=239; d[]=240; d[]=241; d[]=242; d[]=243; d[]=244; d[]=245; d[]=246; d[]=247; d[]=248; d[]=249; d[]=250; d[]=251; d[]=252; d[]=253; d[]=254; d[]=255; d[]=256; d[]=257; d[]=258
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], [], false, [],
    [], [], ["waf.requests" => [[2.0, ""], [1.0, "a=b"]]]]))
]);

rinit();
rshutdown();

$commands = $helper->get_commands();
var_dump(count($commands[1][1][0]['server.request.cookies']['d']));
?>
--EXPECTF--
Notice: datadog\appsec\testing\rshutdown(): Would call ddtrace_metric_register_buffer with name=waf.requests type=1 ns=3 in %s on line %d

Notice: datadog\appsec\testing\rshutdown(): Would call to ddtrace_metric_add_point with name=waf.requests value=2.000000 tags=input_truncated=true in %s on line %d

Notice: datadog\appsec\testing\rshutdown(): Would call ddtrace_metric_register_buffer with name=waf.requests type=1 ns=3 in %s on line %d

Notice: datadog\appsec\testing\rshutdown(): Would call to ddtrace_metric_add_point with name=waf.requests value=1.000000 tags=a=b,input_truncated=true in %s on line %d
int(256)