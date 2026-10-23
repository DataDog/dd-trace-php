use crate::{ddog_appsec_body_private_drop, AppsecBodyMessagePack, AppsecBodyStatus};

/// Renders MessagePack as compact JSON-like text, preserving key order.
pub(super) fn render(body: AppsecBodyMessagePack) -> (AppsecBodyStatus, String) {
    use rmpv::Value;
    use std::vec::Vec;

    fn text(value: &Value) -> String {
        match value {
            Value::Nil => "null".into(),
            Value::Boolean(v) => v.to_string(),
            Value::Integer(v) => v.to_string(),
            Value::F32(v) => format!("{v:?}"),
            Value::F64(v) => format!("{v:?}"),
            Value::String(v) => format!("{:?}", v.as_str().unwrap()),
            Value::Array(values) => format!(
                "[{}]",
                values.iter().map(text).collect::<Vec<_>>().join(",")
            ),
            Value::Map(entries) => format!(
                "{{{}}}",
                entries
                    .iter()
                    .map(|(key, value)| format!("{}:{}", text(key), text(value)))
                    .collect::<Vec<_>>()
                    .join(",")
            ),
            other => panic!("unexpected MessagePack value: {other:?}"),
        }
    }

    let status = body.status;
    let buf = if body.len == 0 {
        Vec::new()
    } else {
        unsafe { std::slice::from_raw_parts(body.ptr, body.len) }.to_vec()
    };
    unsafe {
        ddog_appsec_body_private_drop(body);
    }
    if buf.is_empty() {
        return (status, String::new());
    }
    let mut cursor = std::io::Cursor::new(&buf);
    let value = rmpv::decode::read_value(&mut cursor).unwrap();
    assert_eq!(cursor.position() as usize, buf.len());
    (status, text(&value))
}
