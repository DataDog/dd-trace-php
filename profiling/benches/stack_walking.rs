use criterion::{criterion_group, criterion_main, Criterion};
use datadog_php_profiling::bindings as zend;
use datadog_php_profiling::profiling::stalk_walking::collect_stack_sample;

fn stack_walking(c: &mut Criterion) {
    let shallow_stack = unsafe { zend::ddog_php_test_create_fake_zend_execute_data(1) };
    let deep_stack = unsafe { zend::ddog_php_test_create_fake_zend_execute_data(99) };

    c.bench_function("shallow stack", |b| {
        b.iter(|| unsafe { collect_stack_sample(shallow_stack) })
    });
    c.bench_function("deep stack", |b| {
        b.iter(|| unsafe { collect_stack_sample(deep_stack) })
    });
}

criterion_group!(benches, stack_walking);
criterion_main!(benches);
