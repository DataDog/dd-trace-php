use criterion::{black_box, criterion_group, criterion_main, BenchmarkId, Criterion, SamplingMode};
use datadog_php_profiling::bindings as zend;
use datadog_php_profiling::profiling::stack_walking::collect_stack_sample;

#[cfg(all(target_arch = "x86_64", target_os = "linux"))]
use criterion_perf_events::Perf;
#[cfg(all(target_arch = "x86_64", target_os = "linux"))]
use perfcnt::linux::HardwareEventType as Hardware;
#[cfg(all(target_arch = "x86_64", target_os = "linux"))]
use perfcnt::linux::PerfCounterBuilderLinux as Builder;

fn benchmark(c: &mut Criterion) {
    // Initialize the global ProfilesDictionary and KnownStrings before
    // running benchmarks (normally done in get_module). Only needed on
    // PHP 8.4+ where the dictionary powers the sample API.
    #[cfg(php_opcache_restart_hook)]
    datadog_php_profiling::interning::init();

    let mut group = c.benchmark_group("walk_stack");
    group.sampling_mode(SamplingMode::Flat);
    for depth in [1, 50, 99].iter() {
        let stack = unsafe { zend::ddog_php_test_create_fake_zend_execute_data(99) };
        group.throughput(criterion::Throughput::Elements(*depth as u64));
        group.bench_with_input(BenchmarkId::from_parameter(depth), depth, |b, &_depth| {
            b.iter(|| collect_stack_sample(black_box(stack)))
        });
    }
    group.finish();
}

#[cfg(all(target_arch = "x86_64", target_os = "linux"))]
fn benchmark_instructions(c: &mut Criterion<Perf>) {
    #[cfg(php_opcache_restart_hook)]
    datadog_php_profiling::interning::init();

    let mut group = c.benchmark_group("walk_stack_instructions");
    group.sampling_mode(SamplingMode::Flat);
    for depth in [1, 50, 99].iter() {
        let stack = unsafe { zend::ddog_php_test_create_fake_zend_execute_data(99) };
        group.throughput(criterion::Throughput::Elements(*depth as u64));
        group.bench_with_input(BenchmarkId::from_parameter(depth), depth, |b, &_depth| {
            b.iter(|| collect_stack_sample(black_box(stack)))
        });
    }
    group.finish();
}

criterion_group!(
    name = benches;
    config = Criterion::default();
    targets = benchmark
);

#[cfg(all(target_arch = "x86_64", target_os = "linux"))]
criterion_group!(
    name = instructions_bench;
    config = Criterion::default().with_measurement(Perf::new(Builder::from_hardware_event(Hardware::Instructions)));
    targets = benchmark_instructions
);

#[cfg(all(target_arch = "x86_64", target_os = "linux"))]
criterion_main!(benches, instructions_bench);

#[cfg(not(all(target_arch = "x86_64", target_os = "linux")))]
criterion_main!(benches);
