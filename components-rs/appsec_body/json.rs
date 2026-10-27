//! JSON bodies, parsed with serde_json while writing MessagePack as values
//! arrive, so that everything read before a truncation or syntax error is
//! kept.

use super::{AppsecBodyMessagePack, MsgpackWriter};
use serde::de::{DeserializeSeed, Deserializer, Error, IgnoredAny, MapAccess, SeqAccess, Visitor};
use serde_json::error::Category;
use std::fmt;
use std::ops::Range;

pub(super) fn parse(input: &[u8], max_depth: u32) -> AppsecBodyMessagePack {
    let mut state = State::default();
    let mut deserializer = serde_json::Deserializer::from_slice(input);
    // Not calling Deserializer::end(): data after the first value is ignored.
    let result = ValueSeed {
        state: &mut state,
        depth: 0,
        max_depth,
    }
    .deserialize(&mut deserializer);
    let Err(e) = result else {
        return state.writer.finish(true);
    };
    if e.classify() == Category::Eof {
        let value_failed = state.value_failed || state.writer.mark() == 0;
        if let Some(s) = unterminated_string(input) {
            if value_failed {
                state.writer.str(s.as_bytes());
            }
        } else if let Some(number) = state.last_number {
            // A number ending the input may have lost digits, so unlike a
            // string it is not kept.
            if number.end == state.writer.mark() && input.last().is_some_and(u8::is_ascii_digit) {
                state.writer.discard_last(number.start);
            }
        }
    }
    state.writer.finish(false)
}

#[derive(Default)]
struct State {
    writer: MsgpackWriter,
    /// Set when an array element or object value fails before writing
    /// anything, leaving the writer positioned where that value belongs.
    value_failed: bool,
    /// Writer span of the last number written.
    last_number: Option<Range<usize>>,
}

/// If `input` ends inside a string, decodes its content, dropping any
/// incomplete escape sequence or UTF-8 character at the end.
fn unterminated_string(input: &[u8]) -> Option<String> {
    let span = unterminated_string_span(input)?;
    let mut closed = Vec::with_capacity(span.len() + 1);
    closed.extend_from_slice(&input[span]);
    closed.push(b'"');
    serde_json::from_slice(&closed).ok()
}

/// Returns the span of `input` from the opening quote of the string `input`
/// ends in, up to the longest prefix of `input` which becomes a valid string
/// when a closing quote is appended.
fn unterminated_string_span(input: &[u8]) -> Option<Range<usize>> {
    let len = input.len();
    let mut start = None;
    let mut i = 0;
    while i < len {
        match (start, input[i]) {
            (None, b'"') => start = Some(i),
            (Some(_), b'"') => start = None,
            (Some(s), b'\\') => {
                let escape_len = match input.get(i + 1) {
                    None => return Some(s..i),
                    Some(b'u') => {
                        let is_high_surrogate = input
                            .get(i + 2..i + 6)
                            .and_then(|hex| std::str::from_utf8(hex).ok())
                            .and_then(|hex| u16::from_str_radix(hex, 16).ok())
                            .is_some_and(|c| (0xD800..0xDC00).contains(&c));
                        if is_high_surrogate {
                            12
                        } else {
                            6
                        }
                    }
                    Some(_) => 2,
                };
                if i + escape_len > len {
                    return Some(s..i);
                }
                i += escape_len;
                continue;
            }
            _ => {}
        }
        i += 1;
    }
    let start = start?;
    // The opening quote bounds the backtracking over continuation bytes.
    let mut lead = len;
    while len - lead < 4 && input[lead - 1] & 0xC0 == 0x80 {
        lead -= 1;
    }
    let char_len = match input[lead - 1] {
        0xF0.. => 4,
        0xE0.. => 3,
        0xC0.. => 2,
        _ => 1,
    };
    if lead - 1 + char_len > len {
        Some(start..lead - 1)
    } else {
        Some(start..len)
    }
}

struct ValueSeed<'t> {
    state: &'t mut State,
    /// Number of enclosing containers.
    depth: u32,
    max_depth: u32,
}

impl ValueSeed<'_> {
    fn child(&mut self) -> ValueSeed<'_> {
        ValueSeed {
            state: &mut *self.state,
            depth: self.depth + 1,
            max_depth: self.max_depth,
        }
    }
    /// Records that a value failed without writing anything, which can only
    /// happen for the innermost value being parsed.
    fn check_value_failed(&mut self, before: usize) {
        if self.state.writer.mark() == before {
            self.state.value_failed = true;
        }
    }

    fn number(self, write: impl FnOnce(&mut MsgpackWriter)) {
        let start = self.state.writer.mark();
        write(&mut self.state.writer);
        self.state.last_number = Some(start..self.state.writer.mark());
    }
}

impl<'de> DeserializeSeed<'de> for ValueSeed<'_> {
    type Value = ();

    fn deserialize<D: Deserializer<'de>>(self, deserializer: D) -> Result<(), D::Error> {
        deserializer.deserialize_any(self)
    }
}

impl<'de> Visitor<'de> for ValueSeed<'_> {
    type Value = ();

    fn expecting(&self, formatter: &mut fmt::Formatter) -> fmt::Result {
        formatter.write_str("a JSON value")
    }

    fn visit_bool<E: Error>(self, v: bool) -> Result<(), E> {
        self.state.writer.bool(v);
        Ok(())
    }

    fn visit_i64<E: Error>(self, v: i64) -> Result<(), E> {
        self.number(|w| w.int(v));
        Ok(())
    }

    fn visit_u64<E: Error>(self, v: u64) -> Result<(), E> {
        self.number(|w| match i64::try_from(v) {
            Ok(v) => w.int(v),
            Err(_) => w.float(v as f64),
        });
        Ok(())
    }

    fn visit_f64<E: Error>(self, v: f64) -> Result<(), E> {
        self.number(|w| w.float(v));
        Ok(())
    }

    fn visit_str<E: Error>(self, v: &str) -> Result<(), E> {
        self.state.writer.str(v.as_bytes());
        Ok(())
    }

    fn visit_unit<E: Error>(self) -> Result<(), E> {
        self.state.writer.null();
        Ok(())
    }

    fn visit_seq<A: SeqAccess<'de>>(mut self, mut seq: A) -> Result<(), A::Error> {
        self.state.writer.list_start();
        if self.depth >= self.max_depth {
            self.state.writer.end();
            // serde_json skips ignored values iteratively, so arbitrarily deep
            // nesting can't hide the data following it.
            while seq.next_element::<IgnoredAny>()?.is_some() {}
            return Ok(());
        }
        loop {
            let before = self.state.writer.mark();
            match seq.next_element_seed(self.child()) {
                Ok(Some(())) => {}
                Ok(None) => break,
                Err(e) => {
                    self.check_value_failed(before);
                    return Err(e);
                }
            }
        }
        self.state.writer.end();
        Ok(())
    }

    fn visit_map<A: MapAccess<'de>>(mut self, mut map: A) -> Result<(), A::Error> {
        self.state.writer.map_start();
        if self.depth >= self.max_depth {
            self.state.writer.end();
            while map.next_entry::<IgnoredAny, IgnoredAny>()?.is_some() {}
            return Ok(());
        }
        // On failure, a key left without a value is dropped when the writer
        // closes the map.
        while map
            .next_key_seed(KeySeed(&mut self.state.writer))?
            .is_some()
        {
            let before = self.state.writer.mark();
            if let Err(e) = map.next_value_seed(self.child()) {
                self.check_value_failed(before);
                return Err(e);
            }
        }
        self.state.writer.end();
        Ok(())
    }
}

struct KeySeed<'t>(&'t mut MsgpackWriter);

impl<'de> DeserializeSeed<'de> for KeySeed<'_> {
    type Value = ();

    fn deserialize<D: Deserializer<'de>>(self, deserializer: D) -> Result<(), D::Error> {
        deserializer.deserialize_str(self)
    }
}

impl<'de> Visitor<'de> for KeySeed<'_> {
    type Value = ();

    fn expecting(&self, formatter: &mut fmt::Formatter) -> fmt::Result {
        formatter.write_str("an object key")
    }

    fn visit_str<E: Error>(self, v: &str) -> Result<(), E> {
        self.0.key(v.as_bytes());
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::super::{render, AppsecBodyStatus};

    fn json(input: &str, max_depth: u32) -> (AppsecBodyStatus, String) {
        render(super::parse(input.as_bytes(), max_depth))
    }

    fn partial(input: &str) -> String {
        let (status, out) = json(input, 30);
        assert_eq!(status, AppsecBodyStatus::Partial, "{input}");
        out
    }

    fn complete(input: &str) -> String {
        let (status, out) = json(input, 30);
        assert_eq!(status, AppsecBodyStatus::Complete, "{input}");
        out
    }

    #[test]
    fn truncated() {
        assert_eq!(
            partial(r#"{"key": "value", "number": 42"#),
            r#"{"key":"value"}"#
        );
        assert_eq!(partial("[1, 2, 3, 4"), "[1,2,3]");
        assert_eq!(partial("[1, 2, 3, 4 "), "[1,2,3,4]");
        assert_eq!(partial(r#"[1, "a1"#), r#"[1,"a1"]"#);
        assert_eq!(
            partial(r#"{"outer": {"inner": {"deep": "value"}, "other": 123"#),
            r#"{"outer":{"inner":{"deep":"value"}}}"#
        );
        assert_eq!(
            partial(r#"{"message": "This is a long messa"#),
            r#"{"message":"This is a long messa"}"#
        );
        assert_eq!(partial(r#"{"pi": 3.14159"#), "{}");
        assert_eq!(
            partial(r#"["string", 123, true, null, {"nested": "obj"}"#),
            r#"["string",123,true,null,{"nested":"obj"}]"#
        );
        assert_eq!(
            partial(r#"[{"id": 1, "name": "first"}, {"id": 2, "name": "sec"#),
            r#"[{"id":1,"name":"first"},{"id":2,"name":"sec"}]"#
        );
        assert_eq!(
            partial(r#"{"key1": "value1", "key2":"#),
            r#"{"key1":"value1"}"#
        );
        assert_eq!(partial(r#"{"a": 1, "b": 2,"#), r#"{"a":1,"b":2}"#);
        assert_eq!(partial(r#"{"a": {"#), r#"{"a":{}}"#);
    }

    #[test]
    fn truncated_string() {
        assert_eq!(partial(r#"["abc"#), r#"["abc"]"#);
        assert_eq!(partial(r#""abc"#), r#""abc""#);
        assert_eq!(partial(r#"["#), "[]");
        assert_eq!(partial(r#"[""#), r#"[""]"#);
        assert_eq!(partial(r#"{"a": 1, "ke"#), r#"{"a":1}"#);
        assert_eq!(
            partial(r#"{"a": {"b": "c"}, "d": "e"#),
            r#"{"a":{"b":"c"},"d":"e"}"#
        );
        assert_eq!(json(r#"["a", {"b": "c"#, 1).1, r#"["a",{}]"#);
        assert_eq!(json(r#"["a", ["c"#, 1).1, r#"["a",[]]"#);
        assert_eq!(partial(r#"["a\"b"#), r#"["a\"b"]"#);
        assert_eq!(partial(r#"["ab\"#), r#"["ab"]"#);
        assert_eq!(partial(r#"["ab\u00"#), r#"["ab"]"#);
        assert_eq!(partial(r#"["ab\u0041"#), r#"["abA"]"#);
        assert_eq!(partial(r#"["ab\ud83d"#), r#"["ab"]"#);
        assert_eq!(partial(r#"["ab\ud83d\ude"#), r#"["ab"]"#);
        assert_eq!(partial(r#"["ab\ud83d\ude00"#), r#"["ab😀"]"#);
        assert_eq!(partial(r#"["aé"#), r#"["aé"]"#);
        let (status, out) = render(super::parse("[\"a😀".as_bytes(), 30));
        assert_eq!(
            (status, out.as_str()),
            (AppsecBodyStatus::Partial, r#"["a😀"]"#)
        );
        let (status, out) = render(super::parse(b"[\"a\xf0\x9f\x98", 30));
        assert_eq!(
            (status, out.as_str()),
            (AppsecBodyStatus::Partial, r#"["a"]"#)
        );
    }

    #[test]
    fn values() {
        assert_eq!(
            complete(r#"{"isTrue": true, "isFalse": false}"#),
            r#"{"isTrue":true,"isFalse":false}"#
        );
        assert_eq!(complete("null"), "null");
        assert_eq!(
            complete(r#"{"int": 42, "float": 3.14, "negative": -10, "zero": 0}"#),
            r#"{"int":42,"float":3.14,"negative":-10,"zero":0}"#
        );
        assert_eq!(complete(r#"{"o": {}, "a": []}"#), r#"{"o":{},"a":[]}"#);
        assert_eq!(complete(r#""AB""#), r#""AB""#);
        assert_eq!(complete(r#""just a string""#), r#""just a string""#);
        assert_eq!(complete("9223372036854775807"), "9223372036854775807");
        assert_eq!(complete("18446744073709551615"), "1.8446744073709552e19");
        assert_eq!(complete(r#"{"a": 1} trailing"#), r#"{"a":1}"#);
    }

    #[test]
    fn non_standard_syntax_keeps_preceding_data() {
        assert_eq!(partial(r#"{"a": 1, "b": 2,}"#), r#"{"a":1,"b":2}"#);
        assert_eq!(partial(r#"{"a": 1, /* comment */ "b": 2}"#), r#"{"a":1}"#);
        assert_eq!(partial(r#"{"nan": NaN}"#), "{}");
    }

    #[test]
    fn invalid_utf8() {
        let (status, out) = render(super::parse(b"[\"ok\", \"\xff\"]", 30));
        assert_eq!(status, AppsecBodyStatus::Partial);
        assert_eq!(out, r#"["ok"]"#);
    }

    #[test]
    fn empty() {
        assert_eq!(json("", 30), (AppsecBodyStatus::Empty, String::new()));
        assert_eq!(json("  \n", 30), (AppsecBodyStatus::Empty, String::new()));
        assert_eq!(json("tru", 30), (AppsecBodyStatus::Empty, String::new()));
    }

    #[test]
    fn max_depth() {
        let input = r#"["before",{"2":{"3":{"4":[]}}},"after"]"#;
        assert_eq!(json(r#"["before",[],"after"]"#, 0).1, "[]");
        assert_eq!(json(input, 1).1, r#"["before",{},"after"]"#);
        assert_eq!(json(input, 2).1, r#"["before",{"2":{}},"after"]"#);
        assert_eq!(
            json(r#"["before",{"2":{"3":[]}},"after"]"#, 3).1,
            r#"["before",{"2":{"3":[]}},"after"]"#
        );
    }

    #[test]
    fn deep_nesting_does_not_hide_later_data() {
        let input = format!(
            r#"{{"a":{}{},"payload":"x"}}"#,
            "[".repeat(100_000),
            "]".repeat(100_000)
        );
        assert_eq!(complete_depth(&input, 2), r#"{"a":[[]],"payload":"x"}"#);
    }

    fn complete_depth(input: &str, max_depth: u32) -> String {
        let (status, out) = json(input, max_depth);
        assert_eq!(status, AppsecBodyStatus::Complete);
        out
    }

    #[test]
    fn every_truncation_point() {
        let input = r#"{"a": [1, -2.5, "x\"y", true, false, null, {"b": {}}], "c": "d"}"#;
        let mut previous = String::new();
        for len in 0..=input.len() {
            let expected = match len {
                0 => AppsecBodyStatus::Empty,
                _ if len == input.len() => AppsecBodyStatus::Complete,
                _ => AppsecBodyStatus::Partial,
            };
            let (status, out) = json(&input[..len], 30);
            assert_eq!(status, expected, "{}", &input[..len]);
            // Without the closing delimiters, a longer input may only extend
            // the output: values read are never dropped or changed, though a
            // truncated string may grow.
            let open = out.trim_end_matches(['"', ']', '}']).to_owned();
            assert!(open.starts_with(&previous), "{}: {out}", &input[..len]);
            previous = open;
        }
    }
}
