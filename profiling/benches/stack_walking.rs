use criterion::{black_box, criterion_group, criterion_main, BenchmarkId, Criterion, SamplingMode};
use datadog_php_profiling::bindings as zend;
use datadog_php_profiling::profiling::stalk_walking::collect_stack_sample;

#[cfg(target_os = "linux")]
use criterion_perf_events::{Perf, PerfBuilder};

fn benchmark(c: &mut Criterion) {
    #[cfg(target_os = "linux")]
    let perf = Perf::new(PerfBuilder::new().build()).unwrap();

    let mut group = c.benchmark_group("walk_stack");
    group.sampling_mode(SamplingMode::Flat);
    for depth in [1, 50, 99].iter() {
        let stack = unsafe { zend::ddog_php_test_create_fake_zend_execute_data(99) };
        group.throughput(criterion::Throughput::Elements(*depth as u64));
        group.bench_with_input(BenchmarkId::from_parameter(depth), depth, |b, &_depth| {
            b.iter(|| unsafe { collect_stack_sample(black_box(stack)) })
        });
        #[cfg(target_os = "linux")]
        group.measure(
            perf.clone(),
            BenchmarkId::from_parameter(depth),
            |b, &depth| b.iter(|| walk_stack(stack_ptr)),
        );
    }
    group.finish();
}

criterion_group!(
    name = benches;
    config = Criterion::default();
    targets = benchmark
);
criterion_main!(benches);
