--TEST--
XML parser differences from libxml
--DESCRIPTION--
Each case is a document that libxml accepts but parses differently, with
one representative example per difference. The libxml result comes from
the DOM extension configured as an application would to expand internal
entities safely: LIBXML_NOENT | LIBXML_DTDATTR | LIBXML_NONET, an
external entity loader that refuses every resource, and the default
expansion limits. It is rendered in convert_xml's representation.

Some differences follow XML 1.0 where libxml does not (unread parameter
entities, character references in entity text, CR in entity CDATA,
namespace prefixes in entity content, NUL and encoding errors after the
root, defaults for malformed QNames). Some documents are not well-formed
and both parsers accept them differently (parameter entity references in
declarations within the internal subset). The others are converter
policies: unresolved references stay literal, formatting-only whitespace
is omitted, only UTF-8 and US-ASCII are accepted, and expansion is bounded
by a fixed budget. On a fatal error we stop and keep the data parsed
before it, which libxml's opt-in recover mode does not do: it reports
the content after the error too. Our completeness status is read from
the debug log.
--SKIPIF--
<?php if (!extension_loaded('dom')) die('skip dom extension required'); ?>
--INI--
datadog.appsec.log_level=debug
datadog.appsec.log_file=/tmp/convert_xml_libxml_differences.log
--FILE--
<?php
require __DIR__ . '/inc/convert_xml_status.php';

function libxml_parse($xml, $recover = false)
{
    libxml_use_internal_errors(true);
    libxml_set_external_entity_loader(function () {
        return null;
    });
    $doc = new DOMDocument();
    $doc->recover = $recover;
    $ok = $doc->loadXML($xml, LIBXML_NOENT | LIBXML_DTDATTR | LIBXML_NONET);
    libxml_clear_errors();
    return $ok ? [$doc->documentElement->nodeName => dom_content($doc->documentElement)] : null;
}

// Attributes first, then children. Character data is coalesced across
// comments and PIs, and whitespace-only text is kept, as libxml reports it.
function dom_content(DOMElement $element)
{
    $content = [];
    $attributes = [];
    foreach ($element->attributes as $attribute) {
        $attributes['@' . $attribute->nodeName] = $attribute->value;
    }
    if ($attributes) {
        $content[] = $attributes;
    }
    $text = '';
    foreach ($element->childNodes as $child) {
        if ($child instanceof DOMText) { // includes DOMCdataSection
            $text .= $child->data;
        } elseif ($child instanceof DOMElement) {
            if ($text !== '') {
                $content[] = $text;
                $text = '';
            }
            $content[] = [$child->nodeName => dom_content($child)];
        }
    }
    if ($text !== '') {
        $content[] = $text;
    }
    return $content;
}

// The budget case is large: summarize how many defaults each q received.
function summarize($result)
{
    if (!isset($result['r'])) {
        return json_encode($result);
    }
    $counts = [];
    foreach ($result['r'] as $child) {
        if (isset($child['q'])) {
            $n = isset($child['q'][0]) ? count($child['q'][0]) : 0;
            $counts[$n] = isset($counts[$n]) ? $counts[$n] + 1 : 1;
        }
    }
    krsort($counts);
    return 'q by default count ' . json_encode($counts) . ', last '
        . json_encode(end($result['r']));
}

// The content expansion case is large: count expanded and literal references.
function count_references($result)
{
    $text = isset($result['r'][0]) ? $result['r'][0] : '';
    return intdiv(substr_count($text, 'x'), 1000) . ' references expanded, '
        . substr_count($text, '&e;') . ' literal';
}

$defaults = '';
for ($i = 0; $i < 200; $i++) {
    $defaults .= " d$i CDATA \"\"";
}
$cases = [
    // XML 1.0 section 5.1: later declarations must not be processed.
    'unread parameter entity' => '<!DOCTYPE r [<!ENTITY % ext SYSTEM "urn:unread">%ext;'
        . '<!ENTITY e "declared later">]><r>&e;</r>',
    'external entity' => '<!DOCTYPE r [<!ENTITY e SYSTEM "file:///etc/passwd">]>'
        . '<r>before&e;after</r>',
    // Undeclared after a parameter entity reference, so not fatal.
    'undeclared entity' => '<!DOCTYPE r [<!ENTITY % p "">%p;]><r>&e;</r>',
    // XML 1.0 section 3.3.3: the character reference is appended as is.
    'character reference in attribute entity' => '<!DOCTYPE r [<!ENTITY e "&#38;#9;">]>'
        . '<r a="x&e;y"/>',
    // The CR comes from a character reference, so it is not normalized.
    'CR in entity CDATA' => '<!DOCTYPE r [<!ENTITY e "<![CDATA[&#xD;]]>">]><r>a&e;b</r>',
    // xml:space is ignored as well.
    'formatting whitespace' => "<r xml:space=\"preserve\">\n  <x/>\n</r>",
    'ISO-8859-1' => '<?xml version="1.0" encoding="ISO-8859-1"?><r>text</r>',
    // libxml parses entity content without the outer namespace scope, and
    // drops the prefix. The element's name is p:a in XML 1.0 and Namespaces.
    'namespace prefix in entity content' => '<!DOCTYPE r [<!ENTITY e "<p:a/>">]>'
        . '<r xmlns:p="u">&e;</r>',
    // Not well-formed: PE references are forbidden within markup declarations
    // in the internal subset. libxml expands them; we ignore the declaration.
    'parameter entity in a declaration' => '<!DOCTYPE r [<!ENTITY % t \'CDATA\'>'
        . '<!ENTITY % d "<!ATTLIST r a &#37;t; \'v\'>">%d;]><r/>',
    // Not well-formed for the same reason; the PE is also undeclared.
    'undeclared parameter entity in an entity value' => '<!DOCTYPE r ['
        . '<!ENTITY % d "<!ENTITY e \'a&#37;u;b\'>">%d;]><r>&e;</r>',
    // NUL is not a Char; libxml stops reading there and misses the "<".
    'NUL after the root' => "<r/>\0<",
    // An encoding error is fatal; libxml just ends the input there.
    'non-ASCII after a US-ASCII root' => '<?xml version="1.0" encoding="US-ASCII"?><r/>'
        . "\xc3\xa9",
    // libxml splits the declared name at the colon, so the lookup misses.
    'default for a malformed QName' => '<!DOCTYPE r [<!ATTLIST a: d CDATA "v">]><a:/>',
    // Our budget, max(4 * input, 64 KiB), runs out after 65 KB; libxml allows
    // about 10 MB of entity expansion per document.
    'content expansion' => '<!DOCTYPE r [<!ENTITY e "' . str_repeat('x', 1000) . '">]><r>'
        . str_repeat('&e;', 100) . '</r>',
    // Defaults on 400 elements exhaust the budget before the entity.
    'expansion budget' => "<!DOCTYPE r [<!ATTLIST q$defaults><!ENTITY p \"PAYLOAD\">]><r>"
        . str_repeat('<q/>', 400) . '<v>&p;</v></r>',
];
// libxml's recover mode, which applications must enable explicitly, keeps
// parsing after a fatal error. We stop there, like XMLReader and ext/xml.
$recover = [
    'fatal error in content' => '<r>before<!--a--b--><x>tail</x></r>',
];
foreach ($cases + $recover as $name => $xml) {
    list($status, $ours) = convert_xml_status($xml);
    $libxml = libxml_parse($xml);
    $renderers = ['expansion budget' => 'summarize', 'content expansion' => 'count_references'];
    $render = isset($renderers[$name]) ? $renderers[$name] : 'json_encode';
    echo $name, ":\n";
    echo '  ours:   ', $status, ' ', $render($ours), "\n";
    echo '  libxml: ', $libxml === null ? 'rejected' : 'accepted ' . $render($libxml), "\n";
    if (isset($recover[$name])) {
        echo '  libxml recover: accepted ', $render(libxml_parse($xml, true)), "\n";
    }
}
?>
--CLEAN--
<?php @unlink('/tmp/convert_xml_libxml_differences.log'); ?>
--EXPECT--
unread parameter entity:
  ours:   Complete {"r":["&e;"]}
  libxml: accepted {"r":["declared later"]}
external entity:
  ours:   Complete {"r":["before&e;after"]}
  libxml: accepted {"r":["beforeafter"]}
undeclared entity:
  ours:   Complete {"r":["&e;"]}
  libxml: accepted {"r":[]}
character reference in attribute entity:
  ours:   Complete {"r":[{"@a":"x\ty"}]}
  libxml: accepted {"r":[{"@a":"x y"}]}
CR in entity CDATA:
  ours:   Complete {"r":["a\rb"]}
  libxml: accepted {"r":["a\nb"]}
formatting whitespace:
  ours:   Complete {"r":[{"@xml:space":"preserve"},{"x":[]}]}
  libxml: accepted {"r":[{"@xml:space":"preserve"},"\n  ",{"x":[]},"\n"]}
ISO-8859-1:
  ours:   Empty null
  libxml: accepted {"r":["text"]}
namespace prefix in entity content:
  ours:   Complete {"r":[{"p:a":[]}]}
  libxml: accepted {"r":[{"a":[]}]}
parameter entity in a declaration:
  ours:   Complete {"r":[]}
  libxml: accepted {"r":[{"@a":"v"}]}
undeclared parameter entity in an entity value:
  ours:   Complete {"r":["a%u;b"]}
  libxml: accepted {"r":["ab"]}
NUL after the root:
  ours:   Partial {"r":[]}
  libxml: accepted {"r":[]}
non-ASCII after a US-ASCII root:
  ours:   Partial {"r":[]}
  libxml: accepted {"r":[]}
default for a malformed QName:
  ours:   Complete {"a:":[{"@d":"v"}]}
  libxml: accepted {"a:":[]}
content expansion:
  ours:   Complete 65 references expanded, 35 literal
  libxml: accepted 100 references expanded, 0 literal
expansion budget:
  ours:   Complete q by default count {"200":73,"135":1,"0":326}, last {"v":["&p;"]}
  libxml: accepted q by default count {"200":400}, last {"v":["PAYLOAD"]}
fatal error in content:
  ours:   Partial {"r":["before"]}
  libxml: rejected
  libxml recover: accepted {"r":["before",{"x":["tail"]}]}
