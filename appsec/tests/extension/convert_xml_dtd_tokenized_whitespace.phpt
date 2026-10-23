--TEST--
XML tokenized attributes preserve whitespace introduced by character references
--DESCRIPTION--
XML 1.0 Fifth Edition section 3.3.3 requires the final normalization of
non-CDATA attributes to trim and collapse only space (#x20). Character
references to TAB, LF and CR must survive, including in default values.
Such NMTOKENS values are well-formed but violate a validity constraint;
the specification explicitly illustrates their required normalization.
https://www.w3.org/TR/2008/REC-xml-20081126/#AVNormalize
--INI--
extension=ddtrace.so
--FILE--
<?php
$refs = '  &#x9; A  &#xA;  &#xD; B &#x9;  ';
$cases = [
    'explicit' => '<!DOCTYPE r [<!ATTLIST r a NMTOKENS #IMPLIED>]><r a="' . $refs . '"/>',
    'default' => '<!DOCTYPE r [<!ATTLIST r a NMTOKENS "' . $refs . '">]><r/>',
    'fixed' => '<!DOCTYPE r [<!ATTLIST r a NMTOKENS #FIXED "' . $refs . '">]><r/>',
    // Decoding the ampersand in the entity declaration leaves a character
    // reference to be expanded when the entity is used in the attribute.
    'entity reference' => '<!DOCTYPE r [<!ENTITY e "&#38;#x9;">'
        . '<!ATTLIST r a NMTOKENS #IMPLIED>]><r a="A &e; B"/>',
    // Controls: literal whitespace and whitespace already decoded in an
    // entity declaration must become spaces during attribute normalization.
    'literal' => "<!DOCTYPE r [<!ATTLIST r a NMTOKENS #IMPLIED>]><r a=\"  A\t\n\r B  \"/>",
    'entity whitespace' => '<!DOCTYPE r [<!ENTITY e "&#x9;">'
        . '<!ATTLIST r a NMTOKENS #IMPLIED>]><r a="A &e; B"/>',
];
foreach ($cases as $name => $xml) {
    echo $name, ': ', json_encode(datadog\appsec\convert_xml($xml, 'application/xml')), "\n";
}
?>
--EXPECT--
explicit: {"r":[{"@a":"\t A \n \r B \t"}]}
default: {"r":[{"@a":"\t A \n \r B \t"}]}
fixed: {"r":[{"@a":"\t A \n \r B \t"}]}
entity reference: {"r":[{"@a":"A \t B"}]}
literal: {"r":[{"@a":"A B"}]}
entity whitespace: {"r":[{"@a":"A B"}]}
