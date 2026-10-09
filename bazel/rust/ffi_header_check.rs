use std::env;
use std::fs;
use std::path::Path;

fn main() {
    let mut args = env::args_os().skip(1);
    let generated = args.next().expect("missing generated header");
    let checked_in = args.next().expect("missing checked-in header");
    let marker = args.next().expect("missing marker output");
    assert!(args.next().is_none(), "unexpected extra argument");

    let actual = fs::read(&generated).expect("cannot read generated header");
    let expected = fs::read(&checked_in).expect("cannot read checked-in header");
    if actual != expected {
        let first = actual
            .iter()
            .zip(&expected)
            .position(|(left, right)| left != right)
            .unwrap_or_else(|| actual.len().min(expected.len()));
        panic!(
            "generated header differs from {} at byte {} (generated {} bytes, checked-in {} bytes)",
            Path::new(&checked_in).display(),
            first,
            actual.len(),
            expected.len(),
        );
    }
    fs::write(marker, b"generated FFI header matches checked-in API\n")
        .expect("cannot write header comparison marker");
}
