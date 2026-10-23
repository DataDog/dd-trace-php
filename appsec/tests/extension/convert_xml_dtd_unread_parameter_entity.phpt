--TEST--
XML declarations after an unread parameter entity depend on standalone
--DESCRIPTION--
XML 1.0 Fifth Edition section 5.1 forbids processing entity and attribute
declarations after an unread parameter entity unless standalone="yes".
The skipped entity could contain earlier, overriding declarations.
Declarations read before the skipped reference still apply. An unread
reference inside another parameter entity must also affect the outer DTD.
Unresolved general entities use convert_xml's existing literal retention.
https://www.w3.org/TR/2008/REC-xml-20081126/#proc-types
--INI--
extension=ddtrace.so
--FILE--
<?php
$dtd = <<<'XML'
<!DOCTYPE r [
<!ENTITY before "kept">
<!ATTLIST r kept CDATA "kept">
<!ENTITY % ext SYSTEM "urn:unread">
<!ENTITY % indirect "&#37;ext;">
%ext;
<!ENTITY after "wrong">
<!ATTLIST r added CDATA "wrong" tokens NMTOKENS #IMPLIED>
]>
<r tokens="  a   b  ">&before;|&after;</r>
XML;
$cases = [
    'omitted' => $dtd,
    'no' => '<?xml version="1.0" standalone="no"?>' . $dtd,
    'nested' => str_replace("\n%ext;", "\n%indirect;", $dtd),
    'yes' => '<?xml version="1.0" standalone="yes"?>' . $dtd,
    // Merely declaring an external parameter entity does not skip anything.
    'unreferenced' => str_replace("\n%ext;", '', $dtd),
    'undeclared' => '<!DOCTYPE r [%missing;<!ENTITY e "wrong">'
        . '<!ATTLIST r a CDATA "wrong">]><r>&e;</r>',
    'declared later' => '<!DOCTYPE r [%p;<!ENTITY % p "<!ENTITY e &#39;v&#39;>">'
        . '<!ENTITY f "wrong">]><r>&e;&f;</r>',
    'prior parameter' => '<!DOCTYPE r [<!ENTITY % a "<!ENTITY e &#39;wrong&#39;>">'
        . '<!ENTITY % ext SYSTEM "urn:unread">%ext;%a;]><r>&e;</r>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
omitted: {"r":[{"@tokens":"  a   b  ","@kept":"kept"},"kept|&after;"]}
no: {"r":[{"@tokens":"  a   b  ","@kept":"kept"},"kept|&after;"]}
nested: {"r":[{"@tokens":"  a   b  ","@kept":"kept"},"kept|&after;"]}
yes: {"r":[{"@tokens":"a b","@kept":"kept","@added":"wrong"},"kept|wrong"]}
unreferenced: {"r":[{"@tokens":"a b","@kept":"kept","@added":"wrong"},"kept|wrong"]}
undeclared: {"r":["&e;"]}
declared later: {"r":["&e;&f;"]}
prior parameter: {"r":["&e;"]}
