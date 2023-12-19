#include <iostream>
#include <memory>
#include <benchmark/benchmark.h>
#include <include/testing/fixture.hpp>
#include <Zend/zend_exceptions.h>
//#include "memory_manager.h"

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

/*
static void BM_TeaSapiRshutdown(benchmark::State& state) {
    TeaTestCaseFixture fixture;
    fixture.tea_sapi_spinup();
    for (auto _ : state) {
        fixture.tea_sapi_rshutdown();
    }
    fixture.tea_sapi_mshutdown();
    fixture.tea_sapi_sshutdown();
}
BENCHMARK(BM_TeaSapiRshutdown);

static void BM_TeaSapiMshutdown(benchmark::State& state) {
    TeaTestCaseFixture fixture;
    fixture.tea_sapi_spinup();
    fixture.tea_sapi_rshutdown();
    for (auto _ : state) {
        fixture.tea_sapi_mshutdown();
    }
    fixture.tea_sapi_sshutdown();
}
BENCHMARK(BM_TeaSapiMshutdown);

static void BM_TeaSapiSshutdown(benchmark::State& state) {
    TeaTestCaseFixture fixture;
    fixture.tea_sapi_spinup();
    for (auto _ : state) {
        fixture.tea_sapi_sshutdown();
    }
}
BENCHMARK(BM_TeaSapiSshutdown);
*/

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
/*
int main(int argc, char** argv) {
    ::benchmark::RegisterMemoryManager(mm.get());
    ::benchmark::Initialize(&argc, argv);
    ::benchmark::RunSpecifiedBenchmarks();
    ::benchmark::RegisterMemoryManager(nullptr);
    return 0;
}
*/
