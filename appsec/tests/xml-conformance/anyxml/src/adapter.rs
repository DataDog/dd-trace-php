//! Experimental SAX-to-tape adapter, deliberately outside the PHP extension.
//!
//! Recording caps below bound our output. The local anyxml resource-limit patch
//! separately bounds expansion work and nesting inside the parser.

use super::{AppsecBodyTape, TapeWriter};
use anyxml::error::XMLError;
use anyxml::sax::error::SAXParseError;
use anyxml::sax::{
    Attributes, EntityResolver, ErrorHandler, InputSource, ParserOption, SAXHandler, XMLReader,
};
use anyxml::uri::URIStr;
use serde::Serialize;

const MAX_RECORDED_BYTES: usize = 16 * 1024 * 1024;
const MAX_DIAGNOSTICS: usize = 64;

pub struct Parsed {
    pub tape: AppsecBodyTape,
    /// XML acceptance, independent of the application's recording policy.
    pub accepted: bool,
    pub body_accepted: bool,
    pub error: Option<String>,
    pub body_policy_error: Option<String>,
    pub diagnostics: Vec<Diagnostic>,
    pub denied_resources: usize,
    pub recording_limit_reached: bool,
}

#[derive(Serialize)]
pub struct Diagnostic {
    level: &'static str,
    domain: String,
    message: String,
    line: usize,
    column: usize,
}

/// Same byte input, recording depth and tape representation as xml::parse.
pub fn parse(input: &[u8], max_depth: u32) -> Parsed {
    let (input, valid_utf8) = match std::str::from_utf8(input) {
        Ok(s) => (s, true),
        Err(e) => (
            std::str::from_utf8(&input[..e.valid_up_to()]).unwrap(),
            false,
        ),
    };
    let handler = Handler::new(max_depth);
    let mut reader = XMLReader::builder()
        .set_handler(handler)
        // The local patch processes internal parameter entities independently
        // of validation and external resource loading.
        .disable_option(ParserOption::Validation)
        .disable_option(ParserOption::ExternalGeneralEntities)
        .disable_option(ParserOption::ExternalParameterEntities)
        .disable_option(ParserOption::Namespaces)
        .disable_option(ParserOption::Catalogs)
        .disable_option(ParserOption::CatalogPIAware)
        .build();
    // Explicit UTF-8 disables anyxml's encoding sniffing, including BOM
    // removal. Strip exactly one document BOM, as the current body parser
    // does, and let the byte reader normalize literal line endings.
    let input = input.strip_prefix('\u{feff}').unwrap_or(input);
    let result = reader.parse_reader(std::io::Cursor::new(input.as_bytes()), Some("UTF-8"), None);
    let mut handler = reader.handler;
    let accepted = result.is_ok() && !handler.fatal && valid_utf8;
    let error = result
        .err()
        .map(|e| e.to_string())
        .or_else(|| (!valid_utf8).then(|| "invalid UTF-8; parsed only its valid prefix".into()));
    handler.flush();
    let complete = accepted && !handler.stopped && handler.depth == 0 && handler.suppressed == 0;
    Parsed {
        tape: handler.tape.finish(complete),
        accepted,
        body_accepted: complete,
        error,
        body_policy_error: handler.body_policy_error,
        diagnostics: handler.diagnostics,
        denied_resources: handler.denied_resources,
        recording_limit_reached: handler.recording_limit_reached,
    }
}

struct Handler {
    tape: TapeWriter,
    max_depth: u32,
    depth: u32,
    suppressed: usize,
    root_seen: bool,
    text: Vec<u8>,
    preserve: bool,
    entities: usize,
    cdata: bool,
    fatal: bool,
    stopped: bool,
    recording_limit_reached: bool,
    diagnostics: Vec<Diagnostic>,
    denied_resources: usize,
    body_policy_error: Option<String>,
}

impl Handler {
    fn new(max_depth: u32) -> Self {
        Self {
            tape: TapeWriter::default(),
            max_depth,
            depth: 0,
            suppressed: 0,
            root_seen: false,
            text: Vec::new(),
            preserve: false,
            entities: 0,
            cdata: false,
            fatal: false,
            stopped: false,
            recording_limit_reached: false,
            diagnostics: Vec::new(),
            denied_resources: 0,
            body_policy_error: None,
        }
    }

    fn room(&mut self, additional: usize) -> bool {
        // Reserve enough bytes to close every open tape container. Callback
        // refusal cannot cancel the library; it only prevents further output.
        let size = self
            .tape
            .buf
            .len()
            .saturating_add(self.text.len())
            .saturating_add(self.tape.open)
            .saturating_add(additional);
        if size > MAX_RECORDED_BYTES {
            self.stopped = true;
            self.recording_limit_reached = true;
        }
        !self.stopped
    }

    fn text(&mut self, data: &str) {
        if self.stopped || self.depth == 0 || self.suppressed != 0 {
            return;
        }
        if self.room(data.len().saturating_add(1 + std::mem::size_of::<usize>())) {
            self.text.extend_from_slice(data.as_bytes());
            self.preserve |= self.cdata || self.entities != 0;
        }
    }

    fn flush(&mut self) {
        if !self.text.is_empty()
            && (self.preserve
                || !self
                    .text
                    .iter()
                    .all(|b| matches!(b, b' ' | b'\t' | b'\r' | b'\n')))
        {
            self.tape.str(&self.text);
        }
        self.text.clear();
        self.preserve = false;
    }

    fn diagnostic(&mut self, level: &'static str, error: SAXParseError) {
        if self.diagnostics.len() < MAX_DIAGNOSTICS {
            self.diagnostics.push(Diagnostic {
                level,
                domain: error.domain.to_string(),
                message: error.message.into_owned(),
                line: error.line,
                column: error.column,
            });
        }
    }
}

impl EntityResolver for Handler {
    fn resolve_entity(
        &mut self,
        name: &str,
        _public: Option<&str>,
        _base: &URIStr,
        _system: &URIStr,
    ) -> Result<InputSource<'static>, XMLError> {
        self.denied_resources += 1;
        // anyxml reports a failed external general entity through error(),
        // without skipped_entity(). Preserve the application's literal
        // reference policy here; parameter entities and DTDs are not text.
        if !name.starts_with('%') && !name.starts_with('[') {
            self.text(&format!("&{name};"));
            if self.depth != 0 && self.suppressed == 0 {
                self.preserve = true;
            }
        }
        Err(std::io::Error::new(
            std::io::ErrorKind::PermissionDenied,
            "external resources disabled by body prototype",
        )
        .into())
    }

    fn get_external_subset(
        &mut self,
        _name: &str,
        _base: Option<&URIStr>,
    ) -> Result<InputSource<'static>, XMLError> {
        self.denied_resources += 1;
        Err(std::io::Error::new(
            std::io::ErrorKind::PermissionDenied,
            "external subsets disabled by body prototype",
        )
        .into())
    }
}

impl ErrorHandler for Handler {
    fn fatal_error(&mut self, error: SAXParseError) {
        self.fatal = true;
        self.stopped = true;
        self.diagnostic("fatal", error);
    }
    fn error(&mut self, error: SAXParseError) {
        self.diagnostic("error", error);
    }
    fn warning(&mut self, error: SAXParseError) {
        self.diagnostic("warning", error);
    }
}

impl SAXHandler for Handler {
    fn start_element(
        &mut self,
        _namespace: Option<&str>,
        _local: Option<&str>,
        name: &str,
        attrs: &Attributes,
    ) {
        if self.stopped {
            return;
        }
        self.flush();
        if self.suppressed != 0 || (self.root_seen && self.depth == 0) {
            self.suppressed += 1;
            return;
        }
        let attrs_size = attrs
            .iter()
            .filter(|a| a.qname != "xmlns" && !a.qname.starts_with("xmlns:"))
            .fold(0usize, |n, a| {
                n.saturating_add(a.qname.len())
                    .saturating_add(a.value.len())
                    .saturating_add(3 + 2 * std::mem::size_of::<usize>())
            });
        if !self.room(
            name.len()
                .saturating_add(attrs_size)
                .saturating_add(10 + std::mem::size_of::<usize>()),
        ) {
            return;
        }
        self.root_seen = true;
        self.tape.map_start();
        self.tape.key(name.as_bytes());
        self.tape.list_start();
        let mut opened = false;
        for a in attrs
            .iter()
            .filter(|a| a.qname != "xmlns" && !a.qname.starts_with("xmlns:"))
        {
            if !opened {
                self.tape.map_start();
                opened = true;
            }
            self.tape.key(format!("@{}", a.qname).as_bytes());
            self.tape.str(a.value.as_bytes());
        }
        if opened {
            self.tape.end();
        }
        if self.depth >= self.max_depth {
            self.tape.end();
            self.tape.end();
            self.suppressed = 1;
        } else {
            self.depth += 1;
        }
    }

    fn end_element(&mut self, _namespace: Option<&str>, _local: Option<&str>, _name: &str) {
        if self.stopped {
            return;
        }
        self.flush();
        if self.suppressed != 0 {
            self.suppressed -= 1;
        } else if self.depth != 0 {
            self.tape.end();
            self.tape.end();
            self.depth -= 1;
        }
    }

    fn characters(&mut self, data: &str) {
        self.text(data);
    }
    fn character_reference(&mut self, _value: char) {
        // The parser still delivers the decoded character through characters().
        // Mark the coalesced run without adding text or changing its boundaries.
        if !self.stopped && self.depth != 0 && self.suppressed == 0 {
            self.preserve = true;
        }
    }
    fn ignorable_whitespace(&mut self, data: &str) {
        self.text(data);
    }
    fn start_entity(&mut self, _name: &str) {
        // Even an empty replacement makes its surrounding text run explicit.
        // Mark both boundaries because markup inside the entity can flush it.
        if !self.stopped && self.depth != 0 && self.suppressed == 0 {
            self.preserve = true;
        }
        self.entities += 1;
    }
    fn end_entity(&mut self) {
        self.entities = self.entities.saturating_sub(1);
        if !self.stopped && self.depth != 0 && self.suppressed == 0 {
            self.preserve = true;
        }
    }
    fn start_cdata(&mut self) {
        self.cdata = true;
    }
    fn end_cdata(&mut self) {
        self.cdata = false;
    }

    fn declaration(&mut self, _version: &str, encoding: Option<&str>, _standalone: Option<bool>) {
        // The body API accepts UTF-8 only, regardless of anyxml's encodings.
        if let Some(encoding) = encoding.filter(|s| !s.eq_ignore_ascii_case("UTF-8")) {
            self.body_policy_error = Some(format!("body API only supports UTF-8, not {encoding}"));
            self.stopped = true;
        }
    }

    fn skipped_entity(&mut self, name: &str) {
        if !name.starts_with('%') && !name.starts_with('[') {
            self.text(&format!("&{name};"));
        }
    }
}

#[cfg(test)]
mod tests {
    use super::parse;
    use crate::{body::AppsecBodyStatus, decode_tape};
    use serde_json::json;

    #[test]
    fn writes_defaults_and_markup_from_parameter_entities_to_the_real_tape() {
        let parsed = parse(br#"<!DOCTYPE r [<!ENTITY % d "<!ENTITY e '<x>value</x>'><!ATTLIST r a CDATA 'default'>">%d;]><r>&e;</r>"#, 30);
        assert!(parsed.accepted);
        assert_eq!(parsed.tape.status, AppsecBodyStatus::Complete);
        assert!(parsed.diagnostics.is_empty(), "validation is disabled");
        assert_eq!(
            decode_tape(parsed.tape),
            json!({"r":[{"@a":"default"},{"x":["value"]}]})
        );
    }

    #[test]
    fn normalizes_declared_attributes_and_retains_qnames() {
        let parsed = parse(b"<!DOCTYPE r [<!ATTLIST r a NMTOKENS #IMPLIED>]><r xmlns:p='urn:p' a=' a  b ' p:x='a\tb\nc'>&lt;</r>", 30);
        assert!(parsed.accepted);
        assert_eq!(
            decode_tape(parsed.tape),
            json!({"r":[{"@a":"a b","@p:x":"a b c"},"<"]})
        );
    }

    #[test]
    fn coalesces_text_and_keeps_cdata_and_entity_whitespace() {
        for (input, expected) in [
            ("<r>x<!--comment-->y<?p?>z</r>", json!({"r":["xyz"]})),
            ("<r> \n </r>", json!({"r":[]})),
            ("<r><![CDATA[ \n ]]></r>", json!({"r":[" \n "]})),
            (
                "<!DOCTYPE r [<!ENTITY e ' '>]><r>&e;</r>",
                json!({"r":[" "]}),
            ),
            (
                "<!DOCTYPE r [<!ENTITY e ''>]><r> &e; </r>",
                json!({"r":["  "]}),
            ),
            (
                "<!DOCTYPE r [<!ENTITY e '<a/>'>]><r> &e; </r>",
                json!({"r":[" ",{"a":[]}," "]}),
            ),
        ] {
            let parsed = parse(input.as_bytes(), 30);
            assert!(parsed.accepted, "{input}");
            assert_eq!(decode_tape(parsed.tape), expected, "{input}");
        }
    }

    #[test]
    fn output_depth_keeps_attributes_and_still_checks_suppressed_xml() {
        for (depth, expected) in [
            (0, json!({"r":[{"@a":"v"}]})),
            (
                1,
                json!({"r":[{"@a":"v"},"before",{"x":[{"@a":"w"}]},"after"]}),
            ),
        ] {
            let parsed = parse(b"<r a='v'>before<x a='w'><y/>inside</x>after</r>", depth);
            assert!(parsed.accepted);
            assert_eq!(decode_tape(parsed.tape), expected);
        }
        let parsed = parse(b"<r>before<x>]]></x><tail/></r>", 1);
        assert!(!parsed.accepted);
        assert_eq!(parsed.tape.status, AppsecBodyStatus::Partial);
        assert_eq!(decode_tape(parsed.tape), json!({"r":["before",{"x":[]}]}));
    }

    #[test]
    fn preserves_callbacks_before_fatal_errors() {
        let parsed = parse(b"<r>before<!--bad--comment--><tail/></r>", 30);
        assert!(!parsed.accepted);
        assert_eq!(parsed.tape.status, AppsecBodyStatus::Partial);
        assert_eq!(decode_tape(parsed.tape), json!({"r":["before"]}));
    }

    #[test]
    fn accepts_escaped_less_than_in_an_attribute_entity() {
        let parsed = parse(br#"<!DOCTYPE r [<!ENTITY e "&lt;">]><r a="&e;"/>"#, 30);
        assert!(parsed.accepted);
        assert_eq!(decode_tape(parsed.tape), json!({"r":[{"@a":"<"}]}));
    }

    #[test]
    fn rejects_standalone_undeclared_entities_and_entity_cdata_close() {
        for input in [
            r#"<?xml version="1.0" standalone="yes"?><!DOCTYPE r SYSTEM "file:///not-read"><r>&e;</r>"#,
            r#"<!DOCTYPE r [<!ENTITY e "prefix]]>after">]><r>before&e;<tail/></r>"#,
        ] {
            let parsed = parse(input.as_bytes(), 30);
            assert!(!parsed.accepted, "{input}");
            assert_ne!(parsed.tape.status, AppsecBodyStatus::Complete);
            decode_tape(parsed.tape);
        }
    }

    #[test]
    fn blocks_both_external_entity_and_external_subset_resolution() {
        for input in [
            "<!DOCTYPE r [<!ENTITY e SYSTEM 'file:///not-read'>]><r>&e;</r>",
            "<!DOCTYPE r SYSTEM 'https://example.invalid/not-read'><r/>",
        ] {
            let parsed = parse(input.as_bytes(), 30);
            assert_eq!(parsed.denied_resources, 0);
            decode_tape(parsed.tape);
        }
        let parsed = parse(
            b"<!DOCTYPE r [<!ENTITY e SYSTEM 'file:///not-read'>]><r>before&e;after</r>",
            30,
        );
        assert_eq!(parsed.denied_resources, 0);
        assert_eq!(decode_tape(parsed.tape), json!({"r":["before&e;after"]}));
    }

    #[test]
    fn honors_the_body_utf8_policy() {
        let parsed = parse(b"<r>before<x>\xff</x></r>", 30);
        assert!(!parsed.accepted);
        assert_eq!(parsed.tape.status, AppsecBodyStatus::Partial);
        assert_eq!(decode_tape(parsed.tape), json!({"r":["before",{"x":[]}]}));
        let parsed = parse(
            br#"<?xml version="1.0" encoding="ISO-8859-1"?><r>text</r>"#,
            30,
        );
        assert_eq!(parsed.tape.status, AppsecBodyStatus::Empty);
        assert!(parsed.accepted, "the encoding declaration is legal XML");
        assert!(!parsed.body_accepted, "the body API requires UTF-8");
        assert!(parsed.body_policy_error.is_some());
        assert_eq!(decode_tape(parsed.tape), json!(null));
    }

    #[test]
    fn consumes_document_bom_and_normalizes_only_literal_line_endings() {
        let parsed = parse(
            "\u{feff}<?xml version='1.0'?><r>a\r\nb\rc&#xD;</r>".as_bytes(),
            30,
        );
        assert!(parsed.accepted);
        assert_eq!(decode_tape(parsed.tape), json!({"r":["a\nb\nc\r"]}));
    }
}
