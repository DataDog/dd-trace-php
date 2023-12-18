#[[ Need CMake 3.14 so that `install(TARGETS)` uses better defaults, which
    saves us from repeating configuration in every file (and must be the same).
    Need CMake 3.15+ because the channel component fails on 3.14, as does the
    time component's feature check. Instead of just bumping incrementally, I've
    bumped this to the latest version that's been tested in CI for a while:
    version 3.19.
]]
cmake_minimum_required(VERSION 3.19)

project(datadog-php-components
  VERSION 0.1.0
  LANGUAGES C
)

option(DATADOG_PHP_TESTING "Enable Datadog PHP tests" OFF)
if (${DATADOG_PHP_TESTING})

  # Tests use the C++ testing framework Catch2
  enable_language(CXX)

  # The Catch2::Catch2 target has been available since 2.1.2
  # We are unsure of the true minimum, but have tested 2.4
  find_package(Catch2 2.9 REQUIRED)

  include(Catch)

  if (NOT TARGET Catch2::Catch2WithMain AND TARGET Catch2::Catch2)
    #[[ The build of catch2 we are using wasn't configured with
        `CATCH_BUILD_STATIC_LIBRARY`; let's polyfill it.
    ]]
    file(WRITE ${CMAKE_CURRENT_BINARY_DIR}/catch2withmain.cc
      "#define CATCH_CONFIG_MAIN\n"
      "#define CATCH_CONFIG_ENABLE_BENCHMARKING\n"
      "#include <catch2/catch.hpp>\n"
    )

    add_library(Catch2WithMain ${CMAKE_CURRENT_BINARY_DIR}/catch2withmain.cc)
    target_compile_features(Catch2WithMain INTERFACE cxx_std_11)
    target_link_libraries(Catch2WithMain PUBLIC Catch2::Catch2)
    add_library(Catch2::Catch2WithMain ALIAS Catch2WithMain)
  endif ()

  if (NOT TARGET Catch2::Catch2WithMain)
    message(FATAL_ERROR "Catch2WithMain not found and polyfill failed.")
  endif ()

  enable_testing()
endif ()

include(GNUInstallDirs)

add_library(datadog_php_components INTERFACE)

add_subdirectory(string_view)

add_subdirectory(container_id)
add_subdirectory(sapi)
add_subdirectory(stack-sample)

install(EXPORT DatadogPhpComponentsTargets
  FILE DatadogPhpComponentsTargets.cmake
  NAMESPACE Datadog::Php::
  DESTINATION ${CMAKE_INSTALL_LIBDIR}/cmake
)
