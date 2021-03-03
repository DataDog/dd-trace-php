#include <catch2/catch.hpp>

extern "C" {
#include "pprof.h"
}

const char *empty_string = "";

// cannot use null; use empty_string instead
struct frame {
  const char *function;
  const char *filename;
  int64_t lineno;
  uint64_t function_id;
  uint64_t location_id;
};

TEST_CASE("Basic PHP frame example", "[php]") {
  // This case is specifically crafted to find a bug found when prototyping
  // No bug found in shared library; bug was higher up
  DProf dprof;

  // set up the dprof obj
  dprof.table_type = 1;
  const char *sample_names[2] = {"samples", "wall-time"};
  const char *sample_units[2] = {"count", "nanoseconds"};

  static_assert(sizeof sample_names == sizeof sample_units,
                "number of sample names and units must match");

  std::size_t nsampletypes = sizeof sample_units / sizeof *sample_units;

  pprof_Init(&dprof, sample_names, sample_units, nsampletypes);

  const char *executable_name = "php";
  uint64_t mapping =
      pprof_mapAdd(&dprof, 0, 0, 0, executable_name, empty_string);
  REQUIRE(mapping == 1);

  {
    frame call_stack[4] = {
        {"usleep", "", 0, 1, 1},
        {"Time\\Sleep::usleep", "/home/circleci/app/profile.php", 23, 2, 2},
        {"loop", "/home/circleci/app/profile.php", 37, 3, 3},
        {"", "/home/circleci/app/profile.php", 45, 4, 4},
    };

    uint64_t locations[4];
    uint64_t i = 0;
    for (auto &frame : call_stack) {
      auto function_id =
          pprof_funAdd(&dprof, frame.function, empty_string, frame.filename, 0);
      auto location_id =
          pprof_locAdd(&dprof, mapping, 0, &function_id, &frame.lineno, 1);
      REQUIRE(function_id == frame.function_id);
      REQUIRE(location_id == frame.location_id);
      locations[i++] = location_id;
    }

    int64_t values[2] = {1, 1};
    REQUIRE(pprof_sampleAdd(&dprof, values, sizeof values / sizeof *values,
                            locations, 4) == 0);
  }

  {
    frame call_stack[4] = {
        {"hrtime", "", 0, 5, 5},
        {"Time\\SteadyClock::now", "/home/circleci/app/profile.php", 10, 6, 6},
        {"loop", "/home/circleci/app/profile.php", 37, 3, 3},
        {"", "/home/circleci/app/profile.php", 45, 4, 4},
    };

    uint64_t locations[4];
    uint64_t i = 0;
    for (auto &frame : call_stack) {
      auto function_id =
          pprof_funAdd(&dprof, frame.function, empty_string, frame.filename, 0);
      auto location_id =
          pprof_locAdd(&dprof, mapping, 0, &function_id, &frame.lineno, 1);
      REQUIRE(function_id == frame.function_id);
      REQUIRE(location_id == frame.location_id);
      locations[i++] = location_id;
    }

    int64_t values[2] = {1, 1};
    REQUIRE(pprof_sampleAdd(&dprof, values, sizeof values / sizeof *values,
                            locations, 4) == 0);
  }

  // todo: what other properties should I assert?
  pprof_Free(&dprof);
}
