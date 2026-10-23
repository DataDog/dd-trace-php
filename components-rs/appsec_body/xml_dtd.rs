//! The internal subset needed for body inspection. External identifiers are
//! recorded as unresolved entities; nothing here opens a file or a socket.

use super::xml::{
    character_reference, charge, collapse_spaces, escaped_character, is_name_byte, reference,
    skip_whitespace, MAX_ENTITY_DEPTH,
};
use std::collections::HashMap;
use std::rc::Rc;

#[derive(Default)]
pub(super) struct Dtd {
    pub entities: HashMap<Vec<u8>, Option<Rc<[u8]>>>,
    pub attributes: HashMap<Vec<u8>, Rc<AttributeDefaults>>,
    parameters: HashMap<Vec<u8>, Option<Rc<[u8]>>>,
    allow_undeclared: bool,
}

#[derive(Clone, Default)]
pub(super) struct AttributeDefaults {
    // Also records #IMPLIED and #REQUIRED declarations: the first declaration
    // is binding, even when it has no default value.
    pub cdata: HashMap<Vec<u8>, bool>,
    // Already expanded and normalized at declaration time.
    pub values: Vec<(Vec<u8>, Vec<u8>)>,
}

impl Dtd {
    pub fn parse(&mut self, raw: &[u8], budget: &mut usize, standalone: bool) -> bool {
        let mut header = Cursor(raw);
        header.name(); // The document element name, before the external ID.
        self.allow_undeclared =
            !standalone && (header.consume(b"SYSTEM") || header.consume(b"PUBLIC"));
        self.parse_subset(raw, budget, 0, standalone).is_ok()
    }

    // Ok(false) means a parameter entity was not read. Unless standalone="yes",
    // XML 1.0 section 5.1 forbids using any later entity/attribute declarations,
    // including those in the enclosing subset after this invocation returns.
    // Err means a fatal DTD error: stop the document as well.
    fn parse_subset(
        &mut self,
        raw: &[u8],
        budget: &mut usize,
        depth: usize,
        standalone: bool,
    ) -> Result<bool, ()> {
        let mut cursor = Cursor(raw);
        while !cursor.0.is_empty() {
            // Comments, processing instructions and quoted strings may all
            // contain declaration-looking text. Consume them as a whole.
            if cursor.consume(b"<!--") {
                cursor.comment().ok_or(())?;
            } else if cursor.consume(b"<?") {
                // The PI target "xml" is reserved in every letter case.
                // XML declarations are also forbidden in this subset.
                if cursor.name().eq_ignore_ascii_case(b"xml") {
                    return Err(());
                }
                cursor.through(b"?>");
            } else if cursor.consume(b"<!") {
                let keyword = cursor.name();
                // A DOCTYPE cannot be nested in the internal subset or in
                // a parameter entity expanded there.
                if keyword == b"DOCTYPE" {
                    return Err(());
                }
                let mut declaration = Cursor(cursor.declaration());
                match keyword {
                    b"ENTITY" => self.entity(&mut declaration, budget),
                    b"ATTLIST" => self.attributes(&mut declaration, budget, depth != 0)?,
                    _ => {}
                }
            } else if cursor.consume(b"%") {
                let name = cursor.name();
                if cursor.consume(b";") {
                    // Without standalone="yes", a prior PE reference makes
                    // Entity Declared a validity constraint (XML 1.0 4.1).
                    self.allow_undeclared = !standalone;
                    let value = self
                        .parameters
                        .get(name)
                        .and_then(Clone::clone)
                        .filter(|v| depth < MAX_ENTITY_DEPTH && charge(budget, v.len()));
                    if let Some(value) = value {
                        if !self.parse_subset(&value, budget, depth + 1, standalone)? {
                            return Ok(false);
                        }
                    } else if !standalone {
                        return Ok(false);
                    }
                }
            } else if cursor.quoted().is_none() {
                cursor.0 = cursor.0.get(1..).unwrap_or_default();
            }
        }
        Ok(true)
    }

    fn entity(&mut self, cursor: &mut Cursor<'_>, budget: &mut usize) {
        let parameter = cursor.consume(b"%");
        let name = cursor.name();
        if name.is_empty() {
            return;
        }
        if (if parameter {
            &self.parameters
        } else {
            &self.entities
        })
        .contains_key(name)
        {
            return;
        }
        let value = if let Some(raw) = cursor.quoted() {
            // Character references are resolved when an entity is declared;
            // general entity references are resolved only when it is used.
            Some(Rc::from(self.replacement(raw, budget, 0)))
        } else if cursor.consume(b"SYSTEM") || cursor.consume(b"PUBLIC") {
            None
        } else {
            return;
        };
        let entities = if parameter {
            &mut self.parameters
        } else {
            &mut self.entities
        };
        entities.entry(name.to_vec()).or_insert(value);
    }

    fn replacement(&self, mut raw: &[u8], budget: &mut usize, depth: usize) -> Vec<u8> {
        let mut out = Vec::new();
        while !raw.is_empty() {
            if raw[0] == b'&' || raw[0] == b'%' {
                if let Some((name, after)) = reference(raw) {
                    if raw[0] == b'&' {
                        if let Some(c) = character_reference(name) {
                            out.extend_from_slice(c.encode_utf8(&mut [0; 4]).as_bytes());
                            raw = after;
                            continue;
                        }
                    } else if let Some(Some(value)) = self.parameters.get(name) {
                        if depth < MAX_ENTITY_DEPTH && charge(budget, value.len()) {
                            out.extend_from_slice(&self.replacement(value, budget, depth + 1));
                            raw = after;
                            continue;
                        }
                    }
                }
            }
            out.push(raw[0]);
            raw = &raw[1..];
        }
        out
    }

    fn attributes(
        &mut self,
        cursor: &mut Cursor<'_>,
        budget: &mut usize,
        in_parameter: bool,
    ) -> Result<(), ()> {
        let element = cursor.name();
        if element.is_empty() {
            return Ok(());
        }
        loop {
            let name = cursor.name();
            if name.is_empty() {
                break;
            }
            let cdata = cursor.consume(b"CDATA");
            if !cdata {
                cursor.name(); // A tokenized type, or NOTATION before (...).
                if cursor.consume(b"(") {
                    cursor.through(b")");
                }
            }
            let value = if cursor.consume(b"#IMPLIED") || cursor.consume(b"#REQUIRED") {
                None
            } else {
                cursor.consume(b"#FIXED");
                let Some(value) = cursor.quoted() else {
                    break;
                };
                // Defaults must be resolved now, even if this declaration is
                // unused, overridden, or a duplicate (XML 1.0 3.3.3 and 4.1).
                let mut normalized = Vec::new();
                // Entity Declared excludes references inside parameter
                // entities, even when the document is standalone.
                self.default_text(
                    value,
                    &mut normalized,
                    budget,
                    &mut Vec::new(),
                    self.allow_undeclared || in_parameter,
                )?;
                Some(if cdata {
                    normalized
                } else {
                    collapse_spaces(&normalized)
                })
            };
            let attributes = Rc::make_mut(self.attributes.entry(element.to_vec()).or_default());
            if attributes.cdata.contains_key(name) {
                continue;
            }
            attributes.cdata.insert(name.to_vec(), cdata);
            if let Some(value) = value {
                attributes.values.push((name.to_vec(), value));
            }
        }
        Ok(())
    }

    fn default_text<'a>(
        &'a self,
        mut raw: &'a [u8],
        out: &mut Vec<u8>,
        budget: &mut usize,
        active: &mut Vec<&'a [u8]>,
        allow_undeclared: bool,
    ) -> Result<(), ()> {
        while let Some(&byte) = raw.first() {
            match byte {
                b'&' => {
                    let (name, after) = reference(raw).ok_or(())?;
                    if let Some(c) = escaped_character(name) {
                        out.extend_from_slice(c.encode_utf8(&mut [0; 4]).as_bytes());
                    } else if name.contains(&b'#') {
                        return Err(()); // Invalid character reference.
                    } else {
                        match self.entities.get(name) {
                            Some(Some(value)) => {
                                if active.len() >= MAX_ENTITY_DEPTH
                                    || active.contains(&name)
                                    || !charge(budget, value.len())
                                {
                                    return Err(());
                                }
                                active.push(name);
                                self.default_text(value, out, budget, active, allow_undeclared)?;
                                active.pop();
                            }
                            None if allow_undeclared => {}
                            // Unknown entities are fatal without an external
                            // subset/prior PE reference outside a PE. External
                            // and unparsed entities are always forbidden here.
                            _ => return Err(()),
                        }
                    }
                    raw = after;
                    continue;
                }
                b'<' => return Err(()),
                b'\t' | b'\n' | b'\r' => out.push(b' '),
                _ => out.push(byte),
            }
            raw = &raw[1..];
        }
        Ok(())
    }
}

// quick-xml 0.37 counts raw angle brackets when finding the end of a DOCTYPE.
// XML literals, comments and PIs can contain them freely, so find the boundary
// here before handing the remaining document back to the event reader.
pub(super) fn split_doctype(input: &[u8]) -> Option<(&[u8], &[u8])> {
    let raw = input.strip_prefix(b"<!DOCTYPE")?;
    let mut cursor = Cursor(raw);
    let mut subset = false;
    while let Some(&byte) = cursor.0.first() {
        match byte {
            b'\'' | b'"' => {
                cursor.quoted()?;
            }
            b'<' if cursor.0.starts_with(b"<!--") => {
                cursor.0 = &cursor.0[4..];
                cursor.comment()?;
            }
            b'<' if cursor.0.starts_with(b"<?") => cursor.through(b"?>"),
            b'>' if !subset => {
                let end = raw.len() - cursor.0.len();
                return Some((&raw[..end], &cursor.0[1..]));
            }
            _ => {
                if byte == b'[' {
                    subset = true;
                } else if byte == b']' {
                    subset = false;
                }
                cursor.0 = &cursor.0[1..];
            }
        }
    }
    None
}

struct Cursor<'a>(&'a [u8]);

impl<'a> Cursor<'a> {
    fn consume(&mut self, prefix: &[u8]) -> bool {
        self.0 = skip_whitespace(self.0);
        if let Some(rest) = self.0.strip_prefix(prefix) {
            self.0 = rest;
            true
        } else {
            false
        }
    }

    fn name(&mut self) -> &'a [u8] {
        self.0 = skip_whitespace(self.0);
        let end = self
            .0
            .iter()
            .position(|&b| !is_name_byte(b))
            .unwrap_or(self.0.len());
        let (name, rest) = self.0.split_at(end);
        self.0 = rest;
        name
    }

    fn quoted(&mut self) -> Option<&'a [u8]> {
        self.0 = skip_whitespace(self.0);
        let &quote @ (b'\'' | b'"') = self.0.first()? else {
            return None;
        };
        let end = self.0[1..].iter().position(|&b| b == quote)? + 1;
        let value = &self.0[1..end];
        self.0 = &self.0[end + 1..];
        Some(value)
    }

    // After "<!--", the first double hyphen must be the closing delimiter
    // (XML 1.0 section 2.5). This also rejects a trailing payload hyphen.
    fn comment(&mut self) -> Option<()> {
        let end = self.0.windows(2).position(|w| w == b"--")?;
        self.0 = self.0[end + 2..].strip_prefix(b">")?;
        Some(())
    }

    fn through(&mut self, delimiter: &[u8]) {
        self.0 = self
            .0
            .windows(delimiter.len())
            .position(|w| w == delimiter)
            .map_or(&[], |end| &self.0[end + delimiter.len()..]);
    }

    fn declaration(&mut self) -> &'a [u8] {
        let raw = self.0;
        while !self.0.is_empty() {
            if self.consume(b">") {
                return &raw[..raw.len() - self.0.len() - 1];
            }
            if self.quoted().is_none() {
                self.0 = self.0.get(1..).unwrap_or_default();
            }
        }
        raw
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn truncated_declarations_do_not_panic() {
        let input = br#"r [<!-- <!ENTITY e 'ignored'> --><?p <!ENTITY e 'ignored'>?><!ENTITY e "&#60;x/>"><!ATTLIST r a (one|two) 'one' b CDATA #IMPLIED>] "#;
        for len in 0..=input.len() {
            Dtd::default().parse(&input[..len], &mut 65536, false);
        }
    }
}
