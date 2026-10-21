//! The internal subset needed for body inspection. External identifiers are
//! recorded as unresolved entities; nothing here opens a file or a socket.

use super::xml::{
    character_reference, charge, is_name_byte, reference, skip_whitespace, MAX_ENTITY_DEPTH,
};
use std::collections::HashMap;
use std::rc::Rc;

#[derive(Default)]
pub(super) struct Dtd {
    pub entities: HashMap<Vec<u8>, Option<Rc<[u8]>>>,
    pub attributes: HashMap<Vec<u8>, Rc<AttributeDefaults>>,
    parameters: HashMap<Vec<u8>, Option<Rc<[u8]>>>,
}

#[derive(Clone, Default)]
pub(super) struct AttributeDefaults {
    // Also records #IMPLIED and #REQUIRED declarations: the first declaration
    // is binding, even when it has no default value.
    pub cdata: HashMap<Vec<u8>, bool>,
    pub values: Vec<(Vec<u8>, Vec<u8>)>,
}

impl Dtd {
    pub fn parse(&mut self, raw: &[u8], budget: &mut usize) {
        self.parse_subset(raw, budget, 0);
    }

    fn parse_subset(&mut self, raw: &[u8], budget: &mut usize, depth: usize) {
        let mut cursor = Cursor(raw);
        while !cursor.0.is_empty() {
            // Comments, processing instructions and quoted strings may all
            // contain declaration-looking text. Consume them as a whole.
            if cursor.consume(b"<!--") {
                cursor.through(b"-->");
            } else if cursor.consume(b"<?") {
                cursor.through(b"?>");
            } else if cursor.consume(b"<!") {
                let keyword = cursor.name();
                let mut declaration = Cursor(cursor.declaration());
                match keyword {
                    b"ENTITY" => self.entity(&mut declaration, budget),
                    b"ATTLIST" => self.attributes(&mut declaration),
                    _ => {}
                }
            } else if cursor.consume(b"%") {
                let name = cursor.name();
                if cursor.consume(b";") && depth < MAX_ENTITY_DEPTH {
                    if let Some(Some(value)) = self.parameters.get(name).cloned() {
                        if charge(budget, value.len()) {
                            self.parse_subset(&value, budget, depth + 1);
                        }
                    }
                }
            } else if cursor.quoted().is_none() {
                cursor.0 = cursor.0.get(1..).unwrap_or_default();
            }
        }
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

    fn attributes(&mut self, cursor: &mut Cursor<'_>) {
        let element = cursor.name();
        if element.is_empty() {
            return;
        }
        let attributes = Rc::make_mut(self.attributes.entry(element.to_vec()).or_default());
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
                Some(value)
            };
            if attributes.cdata.contains_key(name) {
                continue;
            }
            attributes.cdata.insert(name.to_vec(), cdata);
            if let Some(value) = value {
                attributes.values.push((name.to_vec(), value.to_vec()));
            }
        }
    }
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
            Dtd::default().parse(&input[..len], &mut 65536);
        }
    }
}
