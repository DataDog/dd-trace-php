use std::{env, fs, path::PathBuf};

fn main() {
    let here = PathBuf::from(env::var_os("CARGO_MANIFEST_DIR").unwrap());
    let body = here
        .join("../../../../components-rs/appsec_body")
        .canonicalize()
        .unwrap();
    let source = fs::read_to_string(body.join("mod.rs")).unwrap();
    // Compile the actual tape writer and current XML parser without linking
    // PHP or changing the production dependency graph. Fail if these anchors
    // change rather than silently testing a stale copy of the implementation.
    let tape = &source[source.find("/// Written as a single byte.").unwrap()
        ..source.find("/// A negative depth").unwrap()];
    let render = &source[source.find("/// Renders a tape").unwrap()..];
    let generated = format!(
        "use std::mem::ManuallyDrop;\n{tape}\n{render}\n\
         #[path = {xml:?}] mod xml;\n\
         #[path = {dtd:?}] mod xml_dtd;\n\
         #[path = {adapter:?}] pub mod adapter;\n\
         pub fn current(input: &[u8], depth: u32) -> AppsecBodyTape {{\n\
             xml::parse(input, depth)\n\
         }}\n",
        xml = body.join("xml.rs"),
        dtd = body.join("xml_dtd.rs"),
        adapter = here.join("src/adapter.rs"),
    );
    fs::write(
        PathBuf::from(env::var_os("OUT_DIR").unwrap()).join("body.rs"),
        generated,
    )
    .unwrap();
    for file in [
        body.join("mod.rs"),
        body.join("xml.rs"),
        body.join("xml_dtd.rs"),
        here.join("src/adapter.rs"),
    ] {
        println!("cargo:rerun-if-changed={}", file.display());
    }
}
