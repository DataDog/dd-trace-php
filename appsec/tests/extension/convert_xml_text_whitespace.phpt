--TEST--
XML text retains whitespace across comments, processing instructions and CDATA
--DESCRIPTION--
XML 1.0 sections 2.5, 2.6 and 2.10 require character data surrounding
comments and processing instructions to survive. Filtering whitespace
must consider the accumulated text rather than individual parser events.
https://www.w3.org/TR/2008/REC-xml-20081126/#sec-white-space
--INI--
extension=ddtrace.so
--FILE--
<?php
$cases = [
    'comments' => '<r>a<!--x--> <!--y-->b</r>',
    'pis' => '<r>a<?p?> <?q?>b</r>',
    'cdata' => '<r><![CDATA[a]]> <![CDATA[b]]></r>',
    'leading' => '<r> <!--x-->a</r>',
    'trailing' => '<r>a<!--x--> </r>',
    'references' => '<r>&#x20;<!--x--> </r>',
    'cdata whitespace' => '<r><![CDATA[ ]]><!--x--> </r>',
    // Conversion intentionally omits formatting-only text between elements.
    'formatting' => "<r>\n <!--x-->\n <a/>\n <?p?>\n <b/>\n </r>",
    'empty entity' => '<!DOCTYPE r [<!ENTITY e "">]><r> &e; </r>',
    'entity markup' => '<!DOCTYPE r [<!ENTITY e "<a/>">]><r> &e; </r>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
comments: {"r":["a b"]}
pis: {"r":["a b"]}
cdata: {"r":["a b"]}
leading: {"r":[" a"]}
trailing: {"r":["a "]}
references: {"r":["  "]}
cdata whitespace: {"r":["  "]}
formatting: {"r":[{"a":[]},{"b":[]}]}
empty entity: {"r":["  "]}
entity markup: {"r":[" ",{"a":[]}," "]}
