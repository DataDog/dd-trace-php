use criterion::{criterion_group, criterion_main, Criterion};

#[allow(unused)]
fn extract_function_name_v1(module_name: &[u8], class_name: &[u8], method_name: &[u8]) -> Vec<u8> {
    let mut buffer = Vec::<u8>::new();

    if !module_name.is_empty() {
        buffer.extend_from_slice(module_name);
        buffer.push(b'|');
    }

    if !class_name.is_empty() {
        buffer.extend_from_slice(class_name);
        buffer.extend_from_slice(b"::");
    }
    buffer.extend_from_slice(method_name);
    buffer
}

#[allow(unused)]
fn extract_function_name_v2(module_name: &[u8], class_name: &[u8], method_name: &[u8]) -> Vec<u8> {
    let opt_module_separator: &[u8] = if module_name.is_empty() { b"" } else { b"|" };
    let opt_class_separator: &[u8] = if class_name.is_empty() { b"" } else { b"::" };
    let cap = module_name.len()
        + opt_module_separator.len()
        + class_name.len()
        + opt_class_separator.len()
        + method_name.len();
    let mut buffer = Vec::<u8>::with_capacity(cap);

    buffer.extend_from_slice(module_name);
    buffer.extend_from_slice(opt_module_separator);
    buffer.extend_from_slice(class_name);
    buffer.extend_from_slice(opt_class_separator);
    buffer.extend_from_slice(method_name);
    buffer
}

fn bench_concatenation_userland(c: &mut Criterion) {
    c.bench_function("bench_concatenation_userland", |b| {
        b.iter(|| {
            for _ in 1..=100 {
                _ = std::hint::black_box(extract_function_name_v2(
                    b"",
                    b"Twig\\Template",
                    b"displayWithErrorHandling",
                ))
            }
        });
    });
}

fn bench_concatenation_internal1(c: &mut Criterion) {
    c.bench_function("bench_concatenation_internal1", |b| {
        b.iter(|| {
            for _ in 1..=100 {
                _ = std::hint::black_box(extract_function_name_v2(
                    b"dom",
                    b"DOMDocumentFragment",
                    b"__construct",
                ))
            }
        });
    });
}

fn bench_concatenation_internal2(c: &mut Criterion) {
    c.bench_function("bench_concatenation_internal2", |b| {
        b.iter(|| {
            for _ in 1..=100 {
                _ = std::hint::black_box(extract_function_name_v2(b"standard", b"", b"file"))
            }
        });
    });
}

criterion_group!(
    benches,
    bench_concatenation_userland,
    bench_concatenation_internal1,
    bench_concatenation_internal2,
);
criterion_main!(benches);
