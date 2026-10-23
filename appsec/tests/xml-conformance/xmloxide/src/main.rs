use base64::Engine;
use serde::Deserialize;
use serde_json::{json, Map, Value};
use std::io::{self, BufRead};
use xmloxide::error::{ParseError, SourceLocation};
use xmloxide::parser::{parse_str_with_options, ParseOptions};
use xmloxide::sax::{parse_sax, SaxHandler};
use xmloxide::tree::{Document, NodeId, NodeKind};

#[derive(Deserialize)]
struct Input {
    name: Value,
    #[serde(default)]
    xml: Option<String>,
    #[serde(default)]
    xml_base64: Option<String>,
    #[serde(default = "default_depth")]
    max_depth: u32,
}
fn default_depth() -> u32 {
    30
}
fn qname(local: &str, prefix: Option<&str>) -> String {
    prefix.map_or_else(|| local.to_owned(), |p| format!("{p}:{local}"))
}
fn xml_space(s: &str) -> bool {
    s.bytes().all(|b| matches!(b, b' ' | b'\t' | b'\r' | b'\n'))
}
struct Frame {
    name: String,
    children: Vec<Value>,
    text: String,
    preserve: bool,
    record: bool,
    content: bool,
}
impl Frame {
    fn flush(&mut self) {
        let text = std::mem::take(&mut self.text);
        if !text.is_empty() && (self.preserve || !xml_space(&text)) {
            self.children.push(json!(text));
        }
        self.preserve = false;
    }
}
struct Handler {
    stack: Vec<Frame>,
    root: Option<Value>,
    events: Vec<Value>,
    max_depth: u32,
}
impl Handler {
    fn new(max_depth: u32) -> Self {
        Self {
            stack: vec![],
            root: None,
            events: vec![],
            max_depth,
        }
    }
    fn open(&mut self, name: String, attributes: Map<String, Value>) {
        let depth = self.stack.len() as u32;
        let record = self.stack.last().map_or(self.root.is_none(), |p| p.content);
        if let Some(parent) = self.stack.last_mut() {
            parent.flush();
        }
        let children = if record && !attributes.is_empty() {
            vec![Value::Object(attributes)]
        } else {
            vec![]
        };
        self.stack.push(Frame {
            name,
            children,
            text: String::new(),
            preserve: false,
            record,
            content: record && depth < self.max_depth,
        });
    }
    fn close(&mut self) {
        if let Some(mut frame) = self.stack.pop() {
            frame.flush();
            if frame.record {
                let mut map = Map::new();
                map.insert(frame.name, Value::Array(frame.children));
                let node = Value::Object(map);
                if let Some(parent) = self.stack.last_mut() {
                    parent.children.push(node);
                } else if self.root.is_none() {
                    self.root = Some(node);
                }
            }
        }
    }
    fn text(&mut self, content: &str, preserve: bool) {
        if let Some(frame) = self.stack.last_mut().filter(|p| p.content) {
            frame.text.push_str(content);
            frame.preserve |= preserve;
        }
    }
    fn finish(&mut self) -> Value {
        while !self.stack.is_empty() {
            self.close();
        }
        self.root.take().unwrap_or(Value::Null)
    }
}
fn location(loc: SourceLocation) -> Value {
    json!({"line":loc.line,"column":loc.column,"byte_offset":loc.byte_offset})
}
fn error_value(e: &ParseError) -> Value {
    json!({"message":e.message,"location":location(e.location),"diagnostics":e.diagnostics.iter().map(|d| json!({"severity":format!("{:?}",d.severity),"message":d.message,"location":location(d.location)})).collect::<Vec<_>>()})
}
impl SaxHandler for Handler {
    fn start_document(&mut self) {
        self.events.push(json!({"event":"start_document"}));
    }
    fn end_document(&mut self) {
        self.events.push(json!({"event":"end_document"}));
    }
    fn start_element(
        &mut self,
        local: &str,
        prefix: Option<&str>,
        ns: Option<&str>,
        attrs: &[(String, String, Option<String>, Option<String>)],
    ) {
        let name = qname(local, prefix);
        self.events
            .push(json!({"event":"start_element","name":name,"namespace":ns,"attributes":attrs}));
        let mut attributes = Map::new();
        for (local, value, prefix, _) in attrs {
            attributes
                .entry(format!("@{}", qname(local, prefix.as_deref())))
                .or_insert(json!(value));
        }
        self.open(name, attributes);
    }
    fn end_element(&mut self, local: &str, prefix: Option<&str>, _ns: Option<&str>) {
        self.events
            .push(json!({"event":"end_element","name":qname(local,prefix)}));
        self.close();
    }
    fn characters(&mut self, s: &str) {
        self.events.push(json!({"event":"characters","text":s}));
        self.text(s, false);
    }
    fn cdata(&mut self, s: &str) {
        self.events.push(json!({"event":"cdata","text":s}));
        self.text(s, true);
    }
    fn comment(&mut self, s: &str) {
        self.events.push(json!({"event":"comment","text":s}));
    }
    fn processing_instruction(&mut self, target: &str, data: Option<&str>) {
        self.events
            .push(json!({"event":"pi","target":target,"data":data}));
    }
    fn warning(&mut self, message: &str, loc: SourceLocation) {
        self.events
            .push(json!({"event":"warning","message":message,"location":location(loc)}));
    }
    fn error(&mut self, message: &str, loc: SourceLocation) {
        self.events
            .push(json!({"event":"error","message":message,"location":location(loc)}));
    }
}
fn dom_walk(doc: &Document, id: NodeId, h: &mut Handler, native: &mut Vec<Value>, in_entity: bool) {
    match &doc.node(id).kind {
        NodeKind::Element {
            name,
            prefix,
            namespace,
            attributes,
        } => {
            native.push(json!({"kind":"element","name":qname(name,prefix.as_deref()),"namespace":namespace,"attributes":attributes.iter().map(|a|json!({"name":qname(&a.name,a.prefix.as_deref()),"value":a.value,"raw_value":a.raw_value})).collect::<Vec<_>>()}));
            let mut attrs = Map::new();
            for a in attributes {
                attrs
                    .entry(format!("@{}", qname(&a.name, a.prefix.as_deref())))
                    .or_insert(json!(a.value));
            }
            h.open(qname(name, prefix.as_deref()), attrs);
            for child in doc.children(id) {
                dom_walk(doc, child, h, native, in_entity);
            }
            h.close();
            native.push(json!({"kind":"end_element","name":qname(name,prefix.as_deref())}));
        }
        NodeKind::Text { content } => {
            native.push(json!({"kind":"text","text":content}));
            h.text(content, in_entity);
        }
        NodeKind::CData { content } => {
            native.push(json!({"kind":"cdata","text":content}));
            h.text(content, true);
        }
        NodeKind::EntityRef { name, value } => {
            native.push(json!({"kind":"entity_ref","name":name,"value":value}));
            if doc.node(id).first_child.is_some() {
                for child in doc.children(id) {
                    dom_walk(doc, child, h, native, true);
                }
            } else {
                h.text(value.as_deref().unwrap_or(&format!("&{name};")), true);
            }
        }
        other => {
            native.push(json!({"kind_debug":format!("{other:?}")}));
            for child in doc.children(id) {
                dom_walk(doc, child, h, native, in_entity);
            }
        }
    }
}
fn run(input: &Input, mode: &str, recover: bool, entity_limit: u32) -> Value {
    let (xml, input_api) = if let Some(encoded) = &input.xml_base64 {
        let bytes = match base64::engine::general_purpose::STANDARD.decode(encoded) {
            Ok(bytes) => bytes,
            Err(e) => {
                return json!({"name":input.name,"accepted":null,"adapter_error":format!("invalid base64: {e}")})
            }
        };
        if bytes.len() > 1024 * 1024 {
            return json!({"name":input.name,"accepted":null,"adapter_error":"input exceeds 1 MiB adapter cap"});
        }
        match xmloxide::encoding::decode_to_utf8(&bytes) {
            Ok(xml) => (xml, "library decode_to_utf8 + string parse"),
            Err(e) => {
                return json!({"name":input.name,"mode":mode,"recover":recover,"accepted":false,"error":{"stage":"library_encoding","message":e.to_string()},"output":null,"events":[]})
            }
        }
    } else if let Some(xml) = &input.xml {
        if xml.len() > 1024 * 1024 {
            return json!({"name":input.name,"accepted":null,"adapter_error":"input exceeds 1 MiB adapter cap"});
        }
        (xml.clone(), "string parse")
    } else {
        return json!({"name":input.name,"accepted":null,"adapter_error":"xml or xml_base64 is required"});
    };
    let options = ParseOptions::default()
        .recover(recover)
        .no_blanks(false)
        .max_depth(256)
        .max_entity_expansions(entity_limit);
    let mut handler = Handler::new(input.max_depth);
    let (accepted, error, diagnostics) = if mode == "dom" {
        match parse_str_with_options(&xml, &options) {
            Ok(doc) => {
                let mut native = vec![];
                dom_walk(&doc, doc.root(), &mut handler, &mut native, false);
                handler.events = native;
                (true,Value::Null,Value::Array(doc.diagnostics.iter().map(|d| json!({"severity":format!("{:?}",d.severity),"message":d.message,"location":location(d.location)})).collect()))
            }
            Err(e) => (false, error_value(&e), Value::Null),
        }
    } else {
        match parse_sax(&xml, &options, &mut handler) {
            Ok(()) => (true, Value::Null, Value::Null),
            Err(e) => (false, error_value(&e), Value::Null),
        }
    };
    let output = handler.finish();
    json!({"name":input.name,"library":"xmloxide","version":"0.5.0","mode":mode,"recover":recover,"accepted":accepted,"error":error,"diagnostics":diagnostics,"output":output,"events":handler.events,"adapter":{"input_api":input_api,"max_depth":input.max_depth,"parser_depth_limit":256,"entity_expansion_limit":entity_limit,"external_resolver":null,"reference_whitespace_provenance_available":false,"partial_output_source":if mode=="sax" {"delivered callbacks, closed by adapter"} else {"returned DOM; strict failure exposes no tree"}}})
}
fn main() {
    let args = std::env::args().skip(1).collect::<Vec<_>>();
    let mode = if args.iter().any(|a| a == "--dom") {
        "dom"
    } else {
        "sax"
    };
    let recover = args.iter().any(|a| a == "--recover");
    let entity_limit = args
        .windows(2)
        .find(|w| w[0] == "--entity-limit")
        .and_then(|w| w[1].parse().ok())
        .unwrap_or(256);
    for line in io::stdin().lock().lines() {
        let result = match line {
            Ok(line) => match serde_json::from_str::<Input>(&line) {
                Ok(input) => match std::panic::catch_unwind(std::panic::AssertUnwindSafe(|| {
                    run(&input, mode, recover, entity_limit)
                })) {
                    Ok(value) => value,
                    Err(_) => json!({"name":input.name,"accepted":null,"library_panic":true}),
                },
                Err(e) => json!({"accepted":null,"adapter_error":e.to_string()}),
            },
            Err(e) => json!({"accepted":null,"adapter_error":e.to_string()}),
        };
        println!("{}", result);
    }
}
