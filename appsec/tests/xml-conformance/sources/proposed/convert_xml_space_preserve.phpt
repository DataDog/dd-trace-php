--TEST--
_convert_xml honors explicit, inherited and defaulted xml:space
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    'explicit' => '<r xml:space="preserve"> </r>',
    'inherited' => '<r xml:space="preserve"> <x> </x> </r>',
    'reset' => '<r xml:space="preserve"> <x xml:space="default"> </x> </r>',
    'defaulted' => '<!DOCTYPE r [<!ATTLIST r xml:space (default|preserve) "preserve">]><r> <x> </x> </r>',
    'default-overridden' => '<!DOCTYPE r [<!ATTLIST r xml:space (default|preserve) "preserve">]><r xml:space="default"> <x> </x> </r>',
    'entity-value' => '<!DOCTYPE r [<!ENTITY e "preserve">]><r xml:space="&e;"> <x/> </r>',
    'comment-pi' => '<r xml:space="preserve"> <!--x--> <?p?> <x/> </r>',
    'empty-entity' => '<!DOCTYPE r [<!ENTITY e "">]><r xml:space="preserve"> &e; <x/> </r>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
explicit: {"r":[{"@xml:space":"preserve"}," "]}
inherited: {"r":[{"@xml:space":"preserve"}," ",{"x":[" "]}," "]}
reset: {"r":[{"@xml:space":"preserve"}," ",{"x":[{"@xml:space":"default"}]}," "]}
defaulted: {"r":[{"@xml:space":"preserve"}," ",{"x":[" "]}," "]}
default-overridden: {"r":[{"@xml:space":"default"},{"x":[]}]}
entity-value: {"r":[{"@xml:space":"preserve"}," ",{"x":[]}," "]}
comment-pi: {"r":[{"@xml:space":"preserve"},"   ",{"x":[]}," "]}
empty-entity: {"r":[{"@xml:space":"preserve"},"  ",{"x":[]}," "]}
