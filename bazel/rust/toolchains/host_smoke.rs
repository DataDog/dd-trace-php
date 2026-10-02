use ddtrace_host_smoke_macro::hermetic_answer;
use std::env;
use std::fs;

fn main() {
    assert_eq!(hermetic_answer!(), 42);
    let output = env::args_os().nth(1).expect("output path argument");
    fs::write(output, b"wrapped rustc + proc macro + static host tool\n")
        .expect("write smoke output");
}
