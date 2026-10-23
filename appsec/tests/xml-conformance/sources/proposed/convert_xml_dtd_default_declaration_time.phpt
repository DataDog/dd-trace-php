--TEST--
_convert_xml processes attribute defaults when declarations are read
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    'forward-default' => '<!DOCTYPE r [<!ATTLIST r a CDATA "&e;"><!ENTITY e "value">]><r/>',
    'unused-default' => '<!DOCTYPE r [<!ATTLIST unused a CDATA "&e;">]><r/>',
    'overridden-default' => '<!DOCTYPE r [<!ATTLIST r a CDATA "&e;">]><r a="override"/>',
    'duplicate-default' => '<!DOCTYPE r [<!ATTLIST r a CDATA "one" a CDATA "&e;">]><r/>',
    'unused-invalid-charref' => '<!DOCTYPE r [<!ATTLIST unused a CDATA "&#0;">]><r/>',
    'forward-with-external-subset' => '<!DOCTYPE r SYSTEM "file:///not-read" [<!ATTLIST r a CDATA "&e;"><!ENTITY e "value">]><r/>',
    'forward-with-parameter-entity' => '<!DOCTYPE r [<!ENTITY % p "">%p;<!ATTLIST r a CDATA "&e;"><!ENTITY e "value">]><r/>',
    'prior-entity' => '<!DOCTYPE r [<!ENTITY e "value"><!ATTLIST r a CDATA "&e;">]><r/>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
forward-default: null
unused-default: null
overridden-default: null
duplicate-default: null
unused-invalid-charref: null
forward-with-external-subset: {"r":[{"@a":""}]}
forward-with-parameter-entity: {"r":[{"@a":""}]}
prior-entity: {"r":[{"@a":"value"}]}
