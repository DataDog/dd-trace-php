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
    'indirect-forward-default' => '<!DOCTYPE r [<!ENTITY e "&later;"><!ATTLIST r a CDATA "&e;"><!ENTITY later "value">]><r/>',
    'implied-duplicate' => '<!DOCTYPE r [<!ATTLIST r a CDATA #IMPLIED><!ATTLIST r a CDATA "&e;">]><r/>',
    'recursive-default' => '<!DOCTYPE r [<!ENTITY e "&e;"><!ATTLIST unused a CDATA "&e;">]><r/>',
    'external-default' => '<!DOCTYPE r [<!ENTITY e SYSTEM "file:///not-read"><!ATTLIST unused a CDATA "&e;">]><r/>',
    'malformed-reference' => '<!DOCTYPE r [<!ATTLIST unused a CDATA "&e">]><r/>',
    'literal-less-than' => '<!DOCTYPE r [<!ATTLIST unused a CDATA "<">]><r/>',
    'forward-with-external-subset' => '<!DOCTYPE r SYSTEM "file:///not-read" [<!ATTLIST r a CDATA "&e;"><!ENTITY e "value">]><r/>',
    'forward-with-public-subset' => '<!DOCTYPE r PUBLIC "example" "file:///not-read" [<!ATTLIST r a CDATA "&e;"><!ENTITY e "value">]><r/>',
    'forward-with-parameter-entity' => '<!DOCTYPE r [<!ENTITY % p "">%p;<!ATTLIST r a CDATA "&e;"><!ENTITY e "value">]><r/>',
    'later-parameter-entity' => '<!DOCTYPE r [<!ENTITY % p ""><!ATTLIST r a CDATA "&e;">%p;]><r/>',
    'parameter-declaration-only' => '<!DOCTYPE r [<!ENTITY % p ""><!ATTLIST r a CDATA "&e;">]><r/>',
    'parameter-reference-in-comment' => '<!DOCTYPE r [<!ENTITY % p ""><!-- %p; --><!ATTLIST r a CDATA "&e;">]><r/>',
    'standalone-external-subset' => '<?xml version="1.0" standalone="yes"?><!DOCTYPE r SYSTEM "file:///not-read" [<!ATTLIST r a CDATA "&e;">]><r/>',
    'standalone-parameter-entity' => '<?xml version="1.0" standalone="yes"?><!DOCTYPE r [<!ENTITY % p "">%p;<!ATTLIST r a CDATA "&e;">]><r/>',
    'standalone-default-inside-parameter-entity' => '<?xml version="1.0" standalone="yes"?><!DOCTYPE r [<!ENTITY % p "<!ATTLIST r a CDATA \'&e;\'>">%p;]><r/>',
    'standalone-indirect-default-inside-parameter-entity' => '<?xml version="1.0" standalone="yes"?><!DOCTYPE r [<!ENTITY e "&later;"><!ENTITY % p "<!ATTLIST r a CDATA \'&e;\'>">%p;]><r/>',
    'standalone-default-inside-nested-parameter-entity' => '<?xml version="1.0" standalone="yes"?><!DOCTYPE r [<!ENTITY % q "<!ATTLIST r a CDATA \'&e;\'>"><!ENTITY % p "&#37;q;">%p;]><r/>',
    'standalone-default-after-parameter-entity' => '<?xml version="1.0" standalone="yes"?><!DOCTYPE r [<!ENTITY % p "<!ATTLIST r a CDATA \'&e;\'>">%p;<!ATTLIST r b CDATA "&e;">]><r/>',
    'invalid-default-inside-parameter-entity' => '<!DOCTYPE r [<!ENTITY % p "<!ATTLIST r a CDATA \'&#38;#0;\'>">%p;]><r/>',
    'prior-entity' => '<!DOCTYPE r [<!ENTITY e "value"><!ATTLIST r a CDATA "&e;">]><r/>',
    'prior-indirect-entity' => '<!DOCTYPE r [<!ENTITY e "&later;"><!ENTITY later "value"><!ATTLIST r a CDATA "&e;">]><r/>',
    'normalized-once' => '<!DOCTYPE r [<!ENTITY e "value"><!ATTLIST r a CDATA "&amp;e; &#38;e; &lt; &#9;"><!ATTLIST r a CDATA "ignored">]><r/>',
    'tokenized-normalized-once' => '<!DOCTYPE r [<!ATTLIST r a NMTOKENS "  a  &#9;  b  ">]><r/>',
    'repeated-default' => '<!DOCTYPE r [<!ENTITY e "value"><!ATTLIST x a CDATA "&amp;e;">]><r><x/><x/></r>',
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
indirect-forward-default: null
implied-duplicate: null
recursive-default: null
external-default: null
malformed-reference: null
literal-less-than: null
forward-with-external-subset: {"r":[{"@a":""}]}
forward-with-public-subset: {"r":[{"@a":""}]}
forward-with-parameter-entity: {"r":[{"@a":""}]}
later-parameter-entity: null
parameter-declaration-only: null
parameter-reference-in-comment: null
standalone-external-subset: null
standalone-parameter-entity: null
standalone-default-inside-parameter-entity: {"r":[{"@a":""}]}
standalone-indirect-default-inside-parameter-entity: {"r":[{"@a":""}]}
standalone-default-inside-nested-parameter-entity: {"r":[{"@a":""}]}
standalone-default-after-parameter-entity: null
invalid-default-inside-parameter-entity: null
prior-entity: {"r":[{"@a":"value"}]}
prior-indirect-entity: {"r":[{"@a":"value"}]}
normalized-once: {"r":[{"@a":"&e; &e; < \t"}]}
tokenized-normalized-once: {"r":[{"@a":"a \t b"}]}
repeated-default: {"r":[{"x":[{"@a":"&e;"}]},{"x":[{"@a":"&e;"}]}]}
