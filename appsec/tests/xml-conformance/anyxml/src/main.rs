use base64::Engine;
use serde::Deserialize;
use serde_json::{json, Map, Value};
use std::io::{self, BufRead};

#[allow(dead_code)]
mod body {
    include!(concat!(env!("OUT_DIR"), "/body.rs"));
}

#[derive(Deserialize)]
struct Input {
    name: Value,
    xml: Option<String>,
    xml_base64: Option<String>,
    #[serde(default = "default_depth")]
    max_depth: u32,
}
fn default_depth() -> u32 {
    30
}

fn main() {
    let args: Vec<_> = std::env::args().skip(1).collect();
    if args.iter().any(|a| a != "--current") {
        eprintln!("usage: anyxml-body-harness [--current] < cases.jsonl");
        std::process::exit(2);
    }
    for line in io::stdin().lock().lines() {
        let result = match line {
            Ok(line) => match serde_json::from_str::<Input>(&line) {
                Ok(input) => run(input, args.iter().any(|a| a == "--current")),
                Err(error) => json!({"accepted":null,"adapter_error":error.to_string()}),
            },
            Err(error) => json!({"accepted":null,"adapter_error":error.to_string()}),
        };
        println!("{result}");
    }
}

fn run(input: Input, current: bool) -> Value {
    let bytes = match (input.xml, input.xml_base64) {
        (Some(xml), None) => xml.into_bytes(),
        (None, Some(encoded)) => match base64::engine::general_purpose::STANDARD.decode(encoded) {
            Ok(bytes) => bytes,
            Err(error) => {
                return json!({"name":input.name,"accepted":null,"adapter_error":error.to_string()})
            }
        },
        _ => {
            return json!({"name":input.name,"accepted":null,"adapter_error":"provide exactly one of xml or xml_base64"})
        }
    };
    if bytes.len() > 1024 * 1024 {
        return json!({"name":input.name,"accepted":null,"adapter_error":"input exceeds prototype's 1 MiB cap"});
    }
    if current {
        let tape = body::current(&bytes, input.max_depth);
        let status = tape.status;
        return json!({"name":input.name,"library":"current-worktree","mode":"sax","accepted":status == body::AppsecBodyStatus::Complete,"status":format!("{status:?}"),"output":decode_tape(tape)});
    }
    let parsed = body::adapter::parse(&bytes, input.max_depth);
    json!({"name":input.name,"library":"anyxml","version":"0.15.1","mode":"sax",
        "patches":["internal-parameter-entities", "resource-limits", "standalone-whitespace", "skipped-dtd-entities", "character-reference-events"],
        "accepted":parsed.accepted,"status":format!("{:?}",parsed.tape.status),
        "body_accepted":parsed.body_accepted,
        "error":parsed.error,"diagnostics":parsed.diagnostics,
        "body_policy_error":parsed.body_policy_error,
        "denied_resources":parsed.denied_resources,"recording_limit_reached":parsed.recording_limit_reached,
        "output":decode_tape(parsed.tape)})
}

/// Consume the same flat tape PHP consumes; no DOM or alternative body builder.
fn decode_tape(tape: body::AppsecBodyTape) -> Value {
    let bytes = unsafe { Vec::from_raw_parts(tape.ptr, tape.len, tape.capacity) };
    if bytes.is_empty() {
        return Value::Null;
    }
    let mut input = bytes.as_slice();
    let result = decode_value(&mut input);
    assert!(input.is_empty(), "more than one root on tape");
    result
}

fn take<'a>(input: &mut &'a [u8], len: usize) -> &'a [u8] {
    let (value, rest) = input.split_at(len);
    *input = rest;
    value
}
fn string(input: &mut &[u8]) -> String {
    let len = usize::from_ne_bytes(
        take(input, std::mem::size_of::<usize>())
            .try_into()
            .unwrap(),
    );
    String::from_utf8(take(input, len).to_vec()).expect("tape must contain UTF-8")
}
fn decode_value(input: &mut &[u8]) -> Value {
    use body::AppsecBodyRecord as R;
    match take(input, 1)[0] {
        t if t == R::MapStart as u8 => {
            let mut map = Map::new();
            while input[0] != R::End as u8 {
                assert_eq!(take(input, 1)[0], R::Key as u8);
                let key = string(input);
                assert!(!map.contains_key(&key), "duplicate tape key");
                map.insert(key, decode_value(input));
            }
            take(input, 1);
            Value::Object(map)
        }
        t if t == R::ListStart as u8 => {
            let mut list = Vec::new();
            while input[0] != R::End as u8 {
                list.push(decode_value(input));
            }
            take(input, 1);
            Value::Array(list)
        }
        t if t == R::Str as u8 => Value::String(string(input)),
        t => panic!("unexpected XML tape record {t}"),
    }
}
