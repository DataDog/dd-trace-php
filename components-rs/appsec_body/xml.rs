//! XML events and entity replacements feed the same writer. Rust owns all
//! parser state; PHP allocations happen only after it returns.

use super::xml_dtd::{split_doctype, Dtd};
use super::{AppsecBodyTape, TapeWriter};
use quick_xml::errors::{Error, SyntaxError};
use quick_xml::events::{BytesStart, Event};
use quick_xml::Reader;
use std::borrow::Cow;
use std::collections::HashSet;
use std::rc::Rc;

/// Bound both bytes and work, including repeated expansion of empty entities.
const MIN_EXPANSION_BUDGET: usize = 64 * 1024;
pub(super) const MAX_ENTITY_DEPTH: usize = 32;

pub(super) fn parse(input: &[u8], max_depth: u32) -> AppsecBodyTape {
    // The body API supports UTF-8 only. Keep the valid prefix on malformed or
    // truncated UTF-8, and never send undecoded bytes to AppSec.
    let (input, valid_utf8) = match std::str::from_utf8(input) {
        Ok(_) => (input, true),
        Err(e) => (&input[..e.valid_up_to()], false),
    };
    // XML normalizes line endings before parsing, including inside DTDs and
    // attributes. Character references to CR are resolved afterwards.
    let normalized = if input.contains(&b'\r') {
        Cow::Owned(
            String::from_utf8_lossy(input)
                .replace("\r\n", "\n")
                .replace('\r', "\n")
                .into_bytes(),
        )
    } else {
        Cow::Borrowed(input)
    };
    let input = normalized.as_ref();
    let mut parser = XmlParser {
        tape: TapeWriter::default(),
        max_depth,
        depth: 0,
        suppressed: 0,
        root_seen: false,
        standalone: false,
        text: Vec::new(),
        preserve_text_whitespace: false,
        dtd: Dtd::default(),
        active_entities: HashSet::new(),
        expansion_budget: input.len().saturating_mul(4).max(MIN_EXPANSION_BUDGET),
    };
    let complete =
        parser.read(input, true) && valid_utf8 && parser.depth == 0 && parser.suppressed == 0;
    parser.flush_text();
    parser.tape.finish(complete)
}

struct XmlParser {
    tape: TapeWriter,
    max_depth: u32,
    /// Number of open elements whose content is recorded.
    depth: u32,
    /// Nesting level inside an element whose content is dropped.
    suppressed: u32,
    root_seen: bool,
    standalone: bool,
    text: Vec<u8>,
    preserve_text_whitespace: bool,
    dtd: Dtd,
    active_entities: HashSet<Vec<u8>>,
    expansion_budget: usize,
}

impl XmlParser {
    fn read(&mut self, input: &[u8], document: bool) -> bool {
        // An entity must balance its own elements, including those whose
        // content is suppressed by the depth limit (XML 1.0 section 4.3.2).
        let initial_depth = (self.depth, self.suppressed);
        let mut input = if document {
            // Strip the document BOM ourselves so buffer positions include
            // exactly the bytes in `input` when intercepting the DOCTYPE.
            input.strip_prefix(b"\xef\xbb\xbf").unwrap_or(input)
        } else {
            // An internal entity's initial U+FEFF is character data, not a
            // BOM. Consume its leading text before starting a fresh reader,
            // which would otherwise discard the first U+FEFF.
            let end = input.iter().position(|&b| b == b'<').unwrap_or(input.len());
            if !self.text(&input[..end]) {
                return false;
            }
            &input[end..]
        };
        // A declaration is allowed only at the literal document start after
        // one optional BOM. Inspect the bytes so the reader cannot hide an
        // extra BOM and make a misplaced declaration appear to be first.
        let mut declaration_allowed = document && input.starts_with(b"<?xml");
        let mut doctype_seen = false;
        let mut reader = xml_reader(input);
        loop {
            let at_document_start = declaration_allowed;
            declaration_allowed = false;
            let event_start = reader.buffer_position() as usize;
            let remaining = &input[event_start..];
            if remaining.starts_with(b"<!DOCTYPE") {
                // The prolog permits one DOCTYPE before the root. Internal
                // entities contain content, not another document prolog.
                if !document || self.root_seen || doctype_seen {
                    return false;
                }
                doctype_seen = true;
                let Some((declaration, after)) = split_doctype(remaining) else {
                    return false;
                };
                if !self
                    .dtd
                    .parse(declaration, &mut self.expansion_budget, self.standalone)
                {
                    return false;
                }
                input = after;
                reader = xml_reader(input);
                continue;
            }
            match reader.read_event() {
                Ok(Event::Start(e)) => self.open_element(e.name().as_ref(), Some(&e), false),
                Ok(Event::Empty(e)) => self.open_element(e.name().as_ref(), Some(&e), true),
                Ok(Event::End(_)) => {
                    // Never let an entity close an element opened by its
                    // caller, even if it would reopen one before returning.
                    if !document && (self.depth, self.suppressed) == initial_depth {
                        return false;
                    }
                    self.end();
                }
                Ok(Event::Text(e)) => {
                    if !self.text(&e) {
                        return false;
                    }
                }
                Ok(Event::CData(e)) => self.raw_text(&e),
                // PITarget excludes exactly "xml" in every letter case.
                Ok(Event::PI(e)) if e.target().eq_ignore_ascii_case(b"xml") => return false,
                Ok(Event::Decl(e)) => {
                    // Reader restarts after a DOCTYPE or for an internal
                    // entity do not create a new document prolog.
                    if !at_document_start {
                        return false;
                    }
                    // VersionInfo is required before the optional fields
                    // (XML 1.0 section 2.8); the reader does not check it.
                    if e.version().is_err() {
                        return false;
                    }
                    self.standalone = match e.standalone() {
                        None => false,
                        Some(Ok(v)) if v.as_ref() == b"yes" => true,
                        Some(Ok(v)) if v.as_ref() == b"no" => false,
                        _ => return false,
                    };
                    if let Some(encoding) = e.encoding() {
                        match encoding {
                            Ok(name) if name.eq_ignore_ascii_case(b"UTF-8") => {}
                            _ => return false,
                        }
                    }
                }
                Ok(Event::Eof) => {
                    return document || (self.depth, self.suppressed) == initial_depth;
                }
                // Supported DOCTYPEs are intercepted above. The event reader
                // also recognizes invalid spellings and can hide a stray BOM.
                Ok(Event::DocType(_)) => return false,
                Ok(_) => {}
                Err(Error::Syntax(SyntaxError::UnclosedTag)) => {
                    self.truncated_start(input.get(event_start..).unwrap_or_default());
                    return false;
                }
                Err(_) => return false,
            }
        }
    }

    fn open_element(&mut self, name: &[u8], e: Option<&BytesStart>, empty: bool) {
        self.flush_text();
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
        let mut seen = HashSet::new();
        let mut opened = false;
        // Clone the Rc, not the declarations: repeated small elements must
        // not repeatedly copy a potentially large DTD.
        let defaults = self.dtd.attributes.get(e.name().as_ref()).cloned();
        for attribute in attributes {
            let Ok(attribute) = attribute else { break };
            let name = attribute.key.into_inner();
            if !seen.insert(name) {
                continue;
            }
            let cdata = defaults
                .as_ref()
                .and_then(|d| d.cdata.get(name))
                .copied()
                .unwrap_or(true);
            self.attribute(name, &attribute.value, cdata, &mut opened);
        }
        if let Some(defaults) = defaults {
            for (name, value) in &defaults.values {
                // Bound work even when defaults are overridden or too large
                // for the remaining expansion budget.
                if !self.charge(1) {
                    break;
                }
                // The name is copied for every matching element, even for an
                // empty value. A long default name must not amplify a small
                // document into an unbounded tape outside PHP's memory limit.
                if !seen.contains(name.as_slice())
                    && self.charge(name.len().saturating_add(value.len()))
                {
                    self.attribute_value(name, value, &mut opened);
                }
            }
        }
        if opened {
            self.tape.end();
        }
    }

    fn attribute(&mut self, name: &[u8], raw: &[u8], cdata: bool, opened: &mut bool) {
        if name == b"xmlns" || name.starts_with(b"xmlns:") {
            return;
        }
        let mut value = Vec::new();
        self.attribute_text(raw, &mut value);
        if !cdata {
            value = collapse_spaces(&value);
        }
        self.attribute_value(name, &value, opened);
    }

    fn attribute_value(&mut self, name: &[u8], value: &[u8], opened: &mut bool) {
        if name == b"xmlns" || name.starts_with(b"xmlns:") {
            return;
        }
        if !*opened {
            self.tape.map_start();
            *opened = true;
        }
        let mut key = Vec::with_capacity(name.len() + 1);
        key.push(b'@');
        key.extend_from_slice(name);
        self.tape.key(&key);
        self.tape.str(value);
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

    fn text(&mut self, mut raw: &[u8]) -> bool {
        // CharData excludes literal "]]>" (XML 1.0 section 2.4). Check before
        // reference expansion: escaped or separately parsed pieces may form
        // this sequence. Keep only the prefix, even when text is suppressed.
        let invalid = raw.windows(3).position(|w| w == b"]]>");
        let complete = invalid.is_none();
        raw = &raw[..invalid.unwrap_or(raw.len())];
        if !self.recording() {
            return complete;
        }
        let preserve_whitespace = !self.active_entities.is_empty() || raw.contains(&b'&');
        while let Some(amp) = raw.iter().position(|&b| b == b'&') {
            self.append_text(&raw[..amp], preserve_whitespace);
            raw = &raw[amp..];
            let Some((name, after)) = reference(raw) else {
                self.text.push(b'&');
                raw = &raw[1..];
                continue;
            };
            if let Some(value) = escaped_character(name) {
                self.text
                    .extend_from_slice(value.encode_utf8(&mut [0; 4]).as_bytes());
            } else if let Some(value) = self.entity(name) {
                // Replacement text can contain markup; use the same XML
                // event handling rather than treating it as character data.
                let complete = self.read(&value, false);
                self.active_entities.remove(name);
                if !complete {
                    return false;
                }
            } else {
                self.text.extend_from_slice(&raw[..name.len() + 2]);
            }
            raw = after;
        }
        self.append_text(raw, preserve_whitespace);
        complete
    }

    fn attribute_text(&mut self, mut raw: &[u8], out: &mut Vec<u8>) {
        while !raw.is_empty() {
            if raw[0] == b'&' {
                if let Some((name, after)) = reference(raw) {
                    if let Some(value) = escaped_character(name) {
                        out.extend_from_slice(value.encode_utf8(&mut [0; 4]).as_bytes());
                    } else if let Some(value) = self.entity(name) {
                        self.attribute_text(&value, out);
                        self.active_entities.remove(name);
                    } else {
                        out.extend_from_slice(&raw[..name.len() + 2]);
                    }
                    raw = after;
                    continue;
                }
            }
            // Literal XML whitespace is normalized in attributes; whitespace
            // introduced by character references above is preserved.
            out.push(if is_whitespace(&raw[..1]) {
                b' '
            } else {
                raw[0]
            });
            raw = &raw[1..];
        }
    }

    fn charge(&mut self, len: usize) -> bool {
        charge(&mut self.expansion_budget, len)
    }

    fn entity(&mut self, name: &[u8]) -> Option<Rc<[u8]>> {
        let value = self.dtd.entities.get(name)?.as_ref()?.clone();
        if self.active_entities.len() >= MAX_ENTITY_DEPTH
            || self.active_entities.contains(name)
            || !self.charge(value.len())
        {
            return None;
        }
        self.active_entities.insert(name.to_vec());
        Some(value)
    }

    fn raw_text(&mut self, raw: &[u8]) {
        // CDATA is explicit character data, including whitespace-only data.
        if self.recording() {
            self.append_text(raw, true);
        }
    }

    fn append_text(&mut self, raw: &[u8], preserve_whitespace: bool) {
        self.text.extend_from_slice(raw);
        self.preserve_text_whitespace |= preserve_whitespace;
    }

    fn flush_text(&mut self) {
        // Comments and PIs split reader events but not character data. Omit
        // formatting-only whitespace only after collecting the whole run;
        // CDATA and entity/character references explicitly preserve it.
        if !self.text.is_empty() && (self.preserve_text_whitespace || !is_whitespace(&self.text)) {
            self.tape.str(&self.text);
        }
        self.text.clear();
        self.preserve_text_whitespace = false;
    }

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
}

fn xml_reader(input: &[u8]) -> Reader<&[u8]> {
    let mut reader = Reader::from_reader(input);
    reader.config_mut().check_comments = true;
    reader.config_mut().check_end_names = false;
    reader.config_mut().allow_unmatched_ends = true;
    reader
}

pub(super) fn charge(budget: &mut usize, len: usize) -> bool {
    let cost = len.max(1);
    if cost > *budget {
        return false;
    }
    *budget -= cost;
    true
}

// Scanning stops at the first non-name byte, including another '&', so
// malformed references cannot turn repeated searches for ';' quadratic.
pub(super) fn reference(raw: &[u8]) -> Option<(&[u8], &[u8])> {
    let end = raw[1..]
        .iter()
        .position(|&b| b != b'#' && !is_name_byte(b))?
        + 1;
    (end > 1 && raw[end] == b';').then_some((&raw[1..end], &raw[end + 1..]))
}

pub(super) fn character_reference(name: &[u8]) -> Option<char> {
    let number = name.strip_prefix(b"#")?;
    let (digits, radix) = match number.strip_prefix(b"x") {
        Some(hex) => (hex, 16),
        None => (number, 10),
    };
    if digits.is_empty() || !digits.iter().all(|b| (*b as char).is_digit(radix)) {
        return None;
    }
    let c = char::from_u32(u32::from_str_radix(std::str::from_utf8(digits).ok()?, radix).ok()?)?;
    matches!(c, '\t' | '\n' | '\r' | '\u{20}'..='\u{d7ff}' | '\u{e000}'..='\u{fffd}' | '\u{10000}'..='\u{10ffff}').then_some(c)
}

pub(super) fn escaped_character(name: &[u8]) -> Option<char> {
    match name {
        b"amp" => Some('&'),
        b"lt" => Some('<'),
        b"gt" => Some('>'),
        b"apos" => Some('\''),
        b"quot" => Some('"'),
        _ => character_reference(name),
    }
}

pub(super) fn collapse_spaces(value: &[u8]) -> Vec<u8> {
    value
        .split(|&b| b == b' ')
        .filter(|s| !s.is_empty())
        .collect::<Vec<_>>()
        .join(&b' ')
}

pub(super) fn is_name_byte(b: u8) -> bool {
    b.is_ascii_alphanumeric() || matches!(b, b'_' | b'-' | b'.' | b':') || b >= 0x80
}

fn is_whitespace(raw: &[u8]) -> bool {
    raw.iter()
        .all(|b| matches!(b, b' ' | b'\t' | b'\n' | b'\r'))
}

pub(super) fn skip_whitespace(raw: &[u8]) -> &[u8] {
    raw.trim_ascii_start()
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
    fn many_attributes() {
        let mut input = String::from("<r");
        for i in 0..40_000 {
            input.push_str(&format!(" a{i}=\"v\""));
        }
        input.push_str("/>");
        let out = complete(&input);
        assert_eq!(out.matches("\":\"v\"").count(), 40_000);
        assert!(out.contains("\"@a39999\":\"v\""));
    }

    #[test]
    fn normalized_whitespace() {
        assert_eq!(
            complete("<r a=\"a\tb\nc\rd\">x\r\ny\rz</r>"),
            r#"{"r":[{"@a":"a b c d"},"x\ny\nz"]}"#
        );
        assert_eq!(
            complete(r#"<!DOCTYPE r [<!ATTLIST r tokens NMTOKENS #IMPLIED>]><r tokens=" a  b "/>"#),
            r#"{"r":[{"@tokens":"a b"}]}"#
        );
        assert_eq!(
            complete(r#"<!DOCTYPE r [<!ENTITY e " ">]><r>a&e;b</r>"#),
            r#"{"r":["a b"]}"#
        );
    }

    #[test]
    fn parameter_entities_can_declare_entities_and_defaults() {
        assert_eq!(
            complete(
                r#"<!DOCTYPE r [<!ENTITY % d "<!ENTITY e 'payload'><!ATTLIST r a CDATA 'default'>">%d;]><r>&e;</r>"#
            ),
            r#"{"r":[{"@a":"default"},"payload"]}"#
        );
    }

    #[test]
    fn cdata_and_comments() {
        assert_eq!(
            complete("<a>x<!-- c -->y<![CDATA[<z>]]></a>"),
            r#"{"a":["xy<z>"]}"#
        );
    }

    #[test]
    fn literal_cdata_close_keeps_only_the_preceding_text() {
        for (text, expected) in [
            ("before]]>after", r#"{"r":["before"]}"#),
            ("before]]]]>after", r#"{"r":["before]]"]}"#),
            ("]]>after", r#"{"r":[]}"#),
            ("before&amp;]]>after", r#"{"r":["before&"]}"#),
        ] {
            assert_eq!(partial(&format!("<r>{text}<x>tail</x></r>")), expected);
        }
        assert_eq!(xml("]]><r/>", 30).0, AppsecBodyStatus::Empty);
        assert_eq!(partial("<r>before</r>]]>"), r#"{"r":["before"]}"#);
        assert_eq!(
            xml("<r>before<x>]]></x><tail/></r>", 1),
            (
                AppsecBodyStatus::Partial,
                r#"{"r":["before",{"x":[]}]}"#.to_owned()
            )
        );
    }

    #[test]
    fn literal_cdata_close_in_entity_replacements_stops_the_document() {
        for replacement in ["prefix]]>after", "prefix]]&#62;after", "prefix]&#93;>after"] {
            let input =
                format!(r#"<!DOCTYPE r [<!ENTITY e "{replacement}">]><r>before&e;<x>tail</x></r>"#);
            assert_eq!(partial(&input), r#"{"r":["beforeprefix"]}"#);
        }
        assert_eq!(
            partial(
                r#"<!DOCTYPE r [<!ENTITY e "<x/>prefix]]>after"><!ENTITY outer "&e;tail">]><r>before&outer;<tail/></r>"#
            ),
            r#"{"r":["before",{"x":[]},"prefix"]}"#
        );
    }

    #[test]
    fn cdata_close_from_separate_text_segments_is_allowed() {
        for text in [
            "]]&gt;",
            "]]&#62;",
            "]&#93;>",
            "]]<!-- c -->>",
            "]]<?p ?>>",
            "]]<![CDATA[>]]>",
        ] {
            assert_eq!(complete(&format!("<r>{text}</r>")), r#"{"r":["]]>"]}"#);
        }
        for (replacement, text) in [
            ("]]", "&e;>"),
            ("]", "]&e;>"),
            ("", "]]&e;>"),
            ("]]&gt;", "&e;"),
            ("]]>unused", "]]&gt;"),
        ] {
            let input = format!(r#"<!DOCTYPE r [<!ENTITY e "{replacement}">]><r>{text}</r>"#);
            assert_eq!(complete(&input), r#"{"r":["]]>"]}"#);
        }
    }

    #[test]
    fn malformed_comments_stop_at_the_parsed_prefix() {
        for comment in ["<!--a--b-->", "<!--a--->", "<!--a"] {
            for input in [
                format!("<r>before{comment}<x>tail</x></r>"),
                format!("<r>before</r>{comment}"),
                format!(
                    r#"<!DOCTYPE r [<!ENTITY e "{comment}<x>tail</x>">]><r>before&e;after</r>"#
                ),
            ] {
                assert_eq!(partial(&input), r#"{"r":["before"]}"#);
            }
            assert_eq!(
                xml(&format!("<r>before<x>{comment}</x><tail/></r>"), 1),
                (
                    AppsecBodyStatus::Partial,
                    r#"{"r":["before",{"x":[]}]}"#.to_owned()
                )
            );
        }
    }

    #[test]
    fn malformed_comments_before_the_root_stop_the_document() {
        for comment in ["<!--a--b-->", "<!--a--->", "<!--a"] {
            for input in [
                format!("{comment}<r>tail</r>"),
                format!("<!DOCTYPE r [{comment}]><r>tail</r>"),
                format!(r#"<!DOCTYPE r [<!ENTITY % p "{comment}">%p;]><r>tail</r>"#),
            ] {
                assert_eq!(xml(&input, 30).0, AppsecBodyStatus::Empty, "{input}");
            }
        }
    }

    #[test]
    fn valid_comments_and_unused_comment_literals_are_preserved() {
        for comment in ["<!---->", "<!---a-->", "<!--a-b-->", "<!-- ]>< -->"] {
            for input in [
                format!("<r>before{comment}after</r>"),
                format!("<!DOCTYPE r [{comment}]><r>beforeafter</r>"),
                format!(r#"<!DOCTYPE r [<!ENTITY % p "{comment}">%p;]><r>beforeafter</r>"#),
                format!(r#"<!DOCTYPE r [<!ENTITY e "{comment}">]><r>before&e;after</r>"#),
            ] {
                assert_eq!(complete(&input), r#"{"r":["beforeafter"]}"#);
            }
        }
        for comment in ["<!--a--b-->", "<!--a--->", "<!--a"] {
            for marker in ["", "% "] {
                let input = format!(r#"<!DOCTYPE r [<!ENTITY {marker}e "{comment}">]><r>tail</r>"#);
                assert_eq!(complete(&input), r#"{"r":["tail"]}"#);
            }
        }
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
    fn dtd_comments_and_quoted_markup_are_not_declarations() {
        assert_eq!(
            complete(
                r#"<!DOCTYPE r [<!-- <!ENTITY a "safe"> --><!ENTITY a "payload">]><r>&a;</r>"#
            ),
            r#"{"r":["payload"]}"#
        );
        assert_eq!(
            complete(
                r#"<!DOCTYPE r [<!ENTITY unused '<!ENTITY a "safe">'><!ENTITY a "payload">]><r>&a;</r>"#
            ),
            r#"{"r":["payload"]}"#
        );
    }

    #[test]
    fn forward_references_and_entity_markup() {
        assert_eq!(
            complete(r#"<!DOCTYPE r [<!ENTITY a "&b;"><!ENTITY b "attack">]><r>&a;</r>"#),
            r#"{"r":["attack"]}"#
        );
        assert_eq!(
            complete(
                r#"<!DOCTYPE r [<!ENTITY e "<child key='v'>value</child>">]><r>before&e;after</r>"#
            ),
            r#"{"r":["before",{"child":[{"@key":"v"},"value"]},"after"]}"#
        );
        assert_eq!(
            complete(r#"<!DOCTYPE r [<!ENTITY e "&#60;child/> &lt;text&gt;">]><r>&e;</r>"#),
            r#"{"r":[{"child":[]}," <text>"]}"#
        );
    }

    #[test]
    fn entity_boundaries_keep_the_parsed_prefix() {
        for (replacement, expected) in [
            ("</r>", r#"{"r":["before"]}"#),
            ("</r><r>", r#"{"r":["before"]}"#),
            ("<a>ok</a></r>", r#"{"r":["before",{"a":["ok"]}]}"#),
            ("<a>", r#"{"r":["before",{"a":[]}]}"#),
            ("<a", r#"{"r":["before",{"a":[]}]}"#),
            ("<!--", r#"{"r":["before"]}"#),
            ("<?p", r#"{"r":["before"]}"#),
            ("<![CDATA[", r#"{"r":["before"]}"#),
        ] {
            let input = format!(
                r#"<!DOCTYPE r [<!ENTITY e "{replacement}">]><r>before&e;after<tail/></r>"#
            );
            assert_eq!(partial(&input), expected, "{replacement}");
        }
        assert_eq!(
            partial(r#"<!DOCTYPE r [<!ENTITY e "</r>">]><r>&e;x</r>"#),
            r#"{"r":[]}"#
        );
        assert_eq!(
            partial(r#"<!DOCTYPE r [<!ENTITY e "</p><p>">]><r><p>before&e;after</p><tail/></r>"#),
            r#"{"r":[{"p":["before"]}]}"#
        );
    }

    #[test]
    fn nested_entity_failures_stop_the_document() {
        for replacement in ["&inner;<a/>", "<a/>&inner;<b/>"] {
            let input = format!(
                r#"<!DOCTYPE r [<!ENTITY inner "</r>"><!ENTITY e "{replacement}">]><r>&e;after<tail/></r>"#
            );
            let expected = if replacement.starts_with('&') {
                r#"{"r":[]}"#
            } else {
                r#"{"r":[{"a":[]}]}"#
            };
            assert_eq!(partial(&input), expected);
        }
        assert_eq!(
            complete(
                r#"<!DOCTYPE r [<!ENTITY inner "<a/>x<b>y</b>"><!ENTITY e "&inner;<c/>">]><r>before&e;after</r>"#
            ),
            r#"{"r":["before",{"a":[]},"x",{"b":["y"]},{"c":[]},"after"]}"#
        );
    }

    #[test]
    fn entity_boundaries_include_suppressed_elements() {
        for (replacement, status, expected) in [
            ("<a>", AppsecBodyStatus::Partial, r#"{"r":[{"a":[]}]}"#),
            (
                "<a></a></r>",
                AppsecBodyStatus::Partial,
                r#"{"r":[{"a":[]}]}"#,
            ),
            (
                "<a><b/></a>",
                AppsecBodyStatus::Complete,
                r#"{"r":[{"a":[]},"after",{"tail":[]}]}"#,
            ),
        ] {
            let input =
                format!(r#"<!DOCTYPE r [<!ENTITY e "{replacement}">]><r>&e;after<tail/></r>"#);
            assert_eq!(xml(&input, 1), (status, expected.to_owned()));
        }
    }

    #[test]
    fn dtd_default_attributes() {
        let dtd = r#"<!DOCTYPE r [
            <!-- <!ATTLIST r cmd CDATA "safe"> -->
            <!ENTITY e "attack">
            <!ATTLIST r cmd CDATA "&e;" fixed CDATA #FIXED "v" required CDATA #REQUIRED implied CDATA #IMPLIED>
            <!ATTLIST r cmd CDATA "ignored">
        ]>"#;
        assert_eq!(
            complete(&format!("{dtd}<r/>")),
            r#"{"r":[{"@cmd":"attack","@fixed":"v"}]}"#
        );
        assert_eq!(
            complete(&format!(r#"{dtd}<r cmd="explicit"/>"#)),
            r#"{"r":[{"@cmd":"explicit","@fixed":"v"}]}"#
        );
    }

    #[test]
    fn invalid_defaults_stop_before_the_root() {
        for declarations in [
            r#"<!ATTLIST r a CDATA "&e;"><!ENTITY e "value">"#,
            r#"<!ATTLIST unused a CDATA "&e;">"#,
            r#"<!ATTLIST r a CDATA "first" a CDATA "&e;">"#,
            r#"<!ENTITY e "&later;"><!ATTLIST r a CDATA "&e;"><!ENTITY later "v">"#,
        ] {
            let input = format!(r#"<!DOCTYPE r [{declarations}]><r a="explicit"/>"#);
            assert_eq!(xml(&input, 30).0, AppsecBodyStatus::Empty, "{input}");
        }
    }

    #[test]
    fn default_expansion_limits_stop_before_the_root() {
        let recursive = r#"<!DOCTYPE r [<!ENTITY e "&e;"><!ATTLIST r a CDATA "&e;">]><r/>"#;
        assert_eq!(xml(recursive, 30).0, AppsecBodyStatus::Empty);

        for repeat in [1, 2] {
            let mut input = String::from(r#"<!DOCTYPE r [<!ENTITY e0 "x">"#);
            let levels = super::MAX_ENTITY_DEPTH / repeat;
            for i in 1..=levels {
                let refs = format!("&e{};", i - 1).repeat(repeat);
                input.push_str(&format!(r#"<!ENTITY e{i} "{refs}">"#));
            }
            input.push_str(&format!(r#"<!ATTLIST r a CDATA "&e{};">]><r/>"#, levels));
            assert_eq!(xml(&input, 30).0, AppsecBodyStatus::Empty);
        }
    }

    #[test]
    fn long_default_attribute_names_are_bounded() {
        let name = "a".repeat(2048);
        let input = format!(
            r#"<!DOCTYPE r [<!ATTLIST a {name} CDATA "">]><r>{}<after>visible</after></r>"#,
            "<a/>".repeat(4000)
        );
        let tape = super::parse(input.as_bytes(), 30);
        assert!(tape.len < input.len() * 8, "{}", tape.len);
        let (status, out) = render(tape);
        assert_eq!(status, AppsecBodyStatus::Complete);
        assert!(out.contains(&format!(r#""@{name}":"""#)));
        assert_eq!(out.matches(r#"{"a":"#).count(), 4000);
        assert!(out.ends_with(r#",{"after":["visible"]}]}"#));
    }

    #[test]
    fn declaration_requires_version_first() {
        for declaration in [
            r#"<?xml encoding="UTF-8"?>"#,
            r#"<?xml standalone="yes"?>"#,
            "<?xml ?>",
            r#"<?xml encoding="UTF-8" version="1.0"?>"#,
            "<?xml version=1.0?>",
        ] {
            for bom in ["", "\u{feff}"] {
                let input = format!("{bom}{declaration}<r>tail</r>");
                assert_eq!(xml(&input, 30).0, AppsecBodyStatus::Empty, "{input}");
            }
        }
    }

    #[test]
    fn standalone_requires_yes_or_no() {
        for value in ["maybe", "", "YES", "No", " yes ", "&#121;es"] {
            let input = format!(r#"<?xml version="1.0" standalone="{value}"?><r>tail</r>"#);
            assert_eq!(xml(&input, 30).0, AppsecBodyStatus::Empty, "{input}");
        }
    }

    #[test]
    fn misplaced_declarations_keep_only_the_preceding_data() {
        for input in [
            r#"<r>before<?xml version="1.0"?><x>tail</x></r>"#,
            r#"<r>before</r><?xml version="1.0"?>"#,
            r#"<!DOCTYPE r [<!ENTITY e "<?xml version='1.0'?><x>tail</x>">]><r>before&e;after</r>"#,
        ] {
            assert_eq!(partial(input), r#"{"r":["before"]}"#);
        }
        assert_eq!(
            xml(r#"<r>before<x><?xml version="1.0"?></x><tail/></r>"#, 1),
            (
                AppsecBodyStatus::Partial,
                r#"{"r":["before",{"x":[]}]}"#.to_owned()
            )
        );
    }

    #[test]
    fn misplaced_declarations_stop_before_the_root() {
        for input in [
            r#" <?xml version="1.0"?><r/>"#,
            r#"<!DOCTYPE r><?xml version="1.0"?><r/>"#,
            r#"<?xml version="1.0"?><?xml version="1.0"?><r/>"#,
            r#"<!DOCTYPE r [<?xml version="1.0"?>]><r/>"#,
            r#"<!DOCTYPE r [<!ENTITY % p "<?xml version='1.0'?>">%p;]><r/>"#,
            "\u{feff}\u{feff}<?xml version=\"1.0\"?><r/>",
        ] {
            assert_eq!(xml(input, 30).0, AppsecBodyStatus::Empty, "{input}");
        }
    }

    #[test]
    fn misplaced_doctypes_keep_only_the_preceding_data() {
        for input in [
            r#"<r>before<!DOCTYPE r [<!ENTITY e "after">]>&e;<tail/></r>"#,
            "<r>before</r><!DOCTYPE r>",
            r#"<!DOCTYPE r [<!ENTITY e "<!DOCTYPE r><tail/>">]><r>before&e;after</r>"#,
        ] {
            assert_eq!(partial(input), r#"{"r":["before"]}"#);
        }
        assert_eq!(partial("<r/><!DOCTYPE r>"), r#"{"r":[]}"#);
        assert_eq!(
            xml("<r>before<x><!DOCTYPE r></x><tail/></r>", 1),
            (
                AppsecBodyStatus::Partial,
                r#"{"r":["before",{"x":[]}]}"#.to_owned()
            )
        );
    }

    #[test]
    fn repeated_and_nested_doctypes_stop_before_the_root() {
        for input in [
            "<!DOCTYPE r><!DOCTYPE r><r/>",
            "<!DOCTYPE r [<!DOCTYPE nested>]><r/>",
            r#"<!DOCTYPE r [<!ENTITY % p "<!DOCTYPE nested>">%p;]><r/>"#,
            "<!DOCTYPE r>\u{feff}<!DOCTYPE r><r/>",
            "\u{feff}\u{feff}<!DOCTYPE r><r/>",
        ] {
            assert_eq!(xml(input, 30).0, AppsecBodyStatus::Empty, "{input}");
        }
    }

    #[test]
    fn reserved_pi_targets_keep_only_the_preceding_data() {
        for target in ["xml", "xmL", "xMl", "xML", "Xml", "XmL", "XMl", "XML"] {
            for input in [
                format!("<r>before<?{target} info?><x>tail</x></r>"),
                format!("<r>before</r><?{target} info?>"),
            ] {
                assert_eq!(partial(&input), r#"{"r":["before"]}"#);
            }
        }
        assert_eq!(
            xml("<r>before<x><?XML info?></x><tail/></r>", 1),
            (
                AppsecBodyStatus::Partial,
                r#"{"r":["before",{"x":[]}]}"#.to_owned()
            )
        );
    }

    #[test]
    fn encoding_is_validated() {
        assert_eq!(
            xml(
                r#"<?xml version="1.0" encoding="ISO-8859-1"?><r>text</r>"#,
                30
            )
            .0,
            AppsecBodyStatus::Empty
        );
        let (status, out) = render(super::parse(b"<r>ok<x>\xff</x></r>", 30));
        assert_eq!(status, AppsecBodyStatus::Partial);
        assert_eq!(out, r#"{"r":["ok",{"x":[]}]}"#);
        assert_eq!(
            complete(r#"<?xml version="1.0" encoding="UTF-8"?><r a="é">日本語</r>"#),
            r#"{"r":[{"@a":"é"},"日本語"]}"#
        );
    }

    #[test]
    fn recursive_and_empty_entities_are_bounded() {
        let input =
            r#"<!DOCTYPE r [<!ENTITY a "&b;"><!ENTITY b "&a;">]><r>&a;<after>visible</after></r>"#;
        assert!(complete(input).ends_with(r#",{"after":["visible"]}]}"#));
        let mut input = String::from(r#"<!DOCTYPE r [<!ENTITY e0 "">"#);
        for i in 1..30 {
            input.push_str(&format!(r#"<!ENTITY e{i} "&e{};&e{};">"#, i - 1, i - 1));
        }
        input.push_str("]><r>&e29;<after>visible</after></r>");
        assert!(complete(&input).contains("visible"));
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
        assert_eq!(xml("<root>", 30).0, AppsecBodyStatus::Partial);
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

    #[test]
    fn doctype_delimiters_and_truncation() {
        let input = "\u{feff}<?xml version=\"1.0\"?>\n<!DOCTYPE r [<!-- ]>< --><?p ]>< ?><!ENTITY e 'a]>b'><!ATTLIST r a CDATA ']>v'>]><r>&e;</r>";
        assert_eq!(complete(input), r#"{"r":[{"@a":"]>v"},"a]>b"]}"#);
        for len in 0..=input.len() {
            let (status, _) = render(super::parse(&input.as_bytes()[..len], 30));
            if len < input.find("<r>").unwrap() {
                assert_eq!(status, AppsecBodyStatus::Empty, "{len}");
            }
        }
    }
}
