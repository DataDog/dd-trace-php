#include <iostream>
#include <memory>
#include <benchmark/benchmark.h>
#include <include/testing/fixture.hpp>
#include <Zend/zend_exceptions.h>

static void BM_TeaSapiSpinup(benchmark::State& state) {
    TeaTestCaseFixture fixture;
    for (auto _ : state) {
        fixture.tea_sapi_spinup();

        state.PauseTiming();
        fixture.tea_sapi_spindown();
        state.ResumeTiming();
    }
}
BENCHMARK(BM_TeaSapiSpinup);

static void BM_TeaSapiSpindown(benchmark::State& state) {
    TeaTestCaseFixture fixture;
    for (auto _ : state) {
        state.PauseTiming();
        fixture.tea_sapi_spinup();
        state.ResumeTiming();

        fixture.tea_sapi_spindown();
    }
}
BENCHMARK(BM_TeaSapiSpindown);

BENCHMARK_MAIN();
