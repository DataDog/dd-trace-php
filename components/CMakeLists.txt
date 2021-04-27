#[[ Need CMake 3.14 so that `install(TARGETS)` uses better defaults, which
    saves us from repeating configuration in every file (and must be the same).
]]
cmake_minimum_required(VERSION 3.14)

project(datadog-php-components
  VERSION 0.1.0
  LANGUAGES C
)

option(BUILD_COMPONENTS_TESTING "Enable tests" OFF)
if (${BUILD_COMPONENTS_TESTING})
  # Tests uses the C++ testing framework Catch2
  enable_language(CXX)

  # The Catch2::Catch2 target has been available since 2.1.2
  # We are unsure of the true minimum, but have tested 2.4
  find_package(Catch2 2.4 REQUIRED)

  #[[ This file takes a while to build, so we do it once here and every test
      executable can link to it to save time.
  ]]
  add_library(catch2_main catch2_main.cc)
  target_link_libraries(catch2_main PUBLIC Catch2::Catch2)
  target_compile_features(catch2_main PUBLIC cxx_std_11)

  include(Catch)
  enable_testing()
endif()

include(GNUInstallDirs)

add_library(datadog_php_components INTERFACE)

add_subdirectory(string_view)

# sapi depends on string_view
add_subdirectory(sapi)

add_subdirectory(container_id)

install(EXPORT DatadogPhpComponentsTargets
  FILE DatadogPhpComponentsTargets.cmake
  NAMESPACE Datadog::Php::
  DESTINATION ${CMAKE_INSTALL_LIBDIR}/cmake
)
