use bindgen::{Builder, FieldVisibilityKind, Formatter};
use std::env;
use std::path::PathBuf;

fn value<'a>(argument: &'a str, name: &str) -> Option<&'a str> {
    argument.strip_prefix(name).and_then(|rest| rest.strip_prefix('='))
}

fn fail(message: impl AsRef<str>) -> ! {
    eprintln!("ddtrace LLVM 20 bindgen: {}", message.as_ref());
    std::process::exit(2);
}

fn main() {
    let mut arguments = env::args().skip(1).peekable();
    let mut builder = Builder::default();
    let mut header = None;
    let mut output = None;
    let mut clang_arguments = Vec::new();

    while let Some(argument) = arguments.next() {
        if argument == "--" {
            clang_arguments.extend(arguments);
            break;
        }
        if argument == "--no-include-path-detection" {
            builder = builder.detect_include_paths(false);
        } else if argument == "--no-doc-comments" {
            builder = builder.generate_comments(false);
        } else if argument == "--no-layout-tests" {
            builder = builder.layout_tests(false);
        } else if argument == "--no-prepend-enum-name" {
            builder = builder.prepend_enum_name(false);
        } else if argument == "--with-derive-default" {
            builder = builder.derive_default(true);
        } else if argument == "--output" {
            output = arguments.next().map(PathBuf::from);
        } else if let Some(path) = value(&argument, "--output") {
            output = Some(PathBuf::from(path));
        } else if let Some(formatter) = value(&argument, "--formatter") {
            if formatter != "none" {
                fail(format!("unsupported formatter {formatter:?}"));
            }
            builder = builder.formatter(Formatter::None);
        } else if let Some(pattern) = value(&argument, "--allowlist-function") {
            builder = builder.allowlist_function(pattern);
        } else if let Some(pattern) = value(&argument, "--blocklist-item") {
            builder = builder.blocklist_item(pattern);
        } else if let Some(prefix) = value(&argument, "--ctypes-prefix") {
            builder = builder.ctypes_prefix(prefix);
        } else if let Some(line) = value(&argument, "--raw-line") {
            builder = builder.raw_line(line);
        } else if let Some(pattern) = value(&argument, "--rustified-enum") {
            builder = builder.rustified_enum(pattern);
        } else if let Some(visibility) = value(&argument, "--default-visibility") {
            if visibility != "public" {
                fail(format!("unsupported default visibility {visibility:?}"));
            }
            builder = builder.default_visibility(FieldVisibilityKind::Public);
        } else if argument.starts_with('-') {
            fail(format!("unsupported option {argument:?}"));
        } else if header.replace(argument).is_some() {
            fail("more than one input header was supplied");
        }
    }

    let header = header.unwrap_or_else(|| fail("missing input header"));
    let output = output.unwrap_or_else(|| fail("missing --output"));
    builder = builder.header(header).clang_args(clang_arguments);
    let bindings = builder
        .generate()
        .unwrap_or_else(|error| fail(format!("generation failed: {error}")));
    bindings
        .write_to_file(&output)
        .unwrap_or_else(|error| fail(format!("cannot write {}: {error}", output.display())));
}
