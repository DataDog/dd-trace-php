//! JSON bodies, parsed with serde_json while writing the tape as values
//! arrive, so that everything read before a truncation or syntax error is
//! kept.

use super::{AppsecBodyTape, TapeWriter};
use serde::de::{DeserializeSeed, Deserializer, Error, IgnoredAny, MapAccess, SeqAccess, Visitor};
use std::fmt;

pub(super) fn parse(input: &[u8], max_depth: u32) -> AppsecBodyTape {
    let mut tape = TapeWriter::default();
    let mut deserializer = serde_json::Deserializer::from_slice(input);
    // Not calling Deserializer::end(): data after the first value is ignored.
    let complete = ValueSeed {
        tape: &mut tape,
        depth: 0,
        max_depth,
    }
    .deserialize(&mut deserializer)
    .is_ok();
    tape.finish(complete)
}

struct ValueSeed<'t> {
    tape: &'t mut TapeWriter,
    /// Number of enclosing containers.
    depth: u32,
    max_depth: u32,
}

impl ValueSeed<'_> {
    fn child(&mut self) -> ValueSeed<'_> {
        ValueSeed {
            tape: &mut *self.tape,
            depth: self.depth + 1,
            max_depth: self.max_depth,
        }
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
        self.tape.bool(v);
        Ok(())
    }

    fn visit_i64<E: Error>(self, v: i64) -> Result<(), E> {
        self.tape.int(v);
        Ok(())
    }

    fn visit_u64<E: Error>(self, v: u64) -> Result<(), E> {
        match i64::try_from(v) {
            Ok(v) => self.tape.int(v),
            Err(_) => self.tape.float(v as f64),
        }
        Ok(())
    }

    fn visit_f64<E: Error>(self, v: f64) -> Result<(), E> {
        self.tape.float(v);
        Ok(())
    }

    fn visit_str<E: Error>(self, v: &str) -> Result<(), E> {
        self.tape.str(v.as_bytes());
        Ok(())
    }

    fn visit_unit<E: Error>(self) -> Result<(), E> {
        self.tape.null();
        Ok(())
    }

    fn visit_seq<A: SeqAccess<'de>>(mut self, mut seq: A) -> Result<(), A::Error> {
        self.tape.list_start();
        if self.depth >= self.max_depth {
            self.tape.end();
            // serde_json skips ignored values iteratively, so arbitrarily deep
            // nesting can't hide the data following it.
            while seq.next_element::<IgnoredAny>()?.is_some() {}
            return Ok(());
        }
        while seq.next_element_seed(self.child())?.is_some() {}
        self.tape.end();
        Ok(())
    }

    fn visit_map<A: MapAccess<'de>>(mut self, mut map: A) -> Result<(), A::Error> {
        self.tape.map_start();
        if self.depth >= self.max_depth {
            self.tape.end();
            while map.next_entry::<IgnoredAny, IgnoredAny>()?.is_some() {}
            return Ok(());
        }
        loop {
            let before_key = self.tape.mark();
            if map.next_key_seed(KeySeed(&mut *self.tape))?.is_none() {
                break;
            }
            let after_key = self.tape.mark();
            if let Err(e) = map.next_value_seed(self.child()) {
                // Drop the key of a value which didn't even start.
                if self.tape.mark() == after_key {
                    self.tape.rollback(before_key);
                }
                return Err(e);
            }
        }
        self.tape.end();
        Ok(())
    }
}

struct KeySeed<'t>(&'t mut TapeWriter);

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
            r#"{"key":"value","number":42}"#
        );
        assert_eq!(partial("[1, 2, 3, 4"), "[1,2,3,4]");
        assert_eq!(
            partial(r#"{"outer": {"inner": {"deep": "value"}, "other": 123"#),
            r#"{"outer":{"inner":{"deep":"value"},"other":123}}"#
        );
        assert_eq!(partial(r#"{"message": "This is a long messa"#), "{}");
        assert_eq!(partial(r#"{"pi": 3.14159"#), r#"{"pi":3.14159}"#);
        assert_eq!(
            partial(r#"["string", 123, true, null, {"nested": "obj"}"#),
            r#"["string",123,true,null,{"nested":"obj"}]"#
        );
        assert_eq!(
            partial(r#"[{"id": 1, "name": "first"}, {"id": 2, "name": "sec"#),
            r#"[{"id":1,"name":"first"},{"id":2}]"#
        );
        assert_eq!(
            partial(r#"{"key1": "value1", "key2":"#),
            r#"{"key1":"value1"}"#
        );
        assert_eq!(partial(r#"{"a": 1, "b": 2,"#), r#"{"a":1,"b":2}"#);
        assert_eq!(partial(r#"{"a": {"#), r#"{"a":{}}"#);
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
        for len in 0..=input.len() {
            json(&input[..len], 30);
        }
    }
}
