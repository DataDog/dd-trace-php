# Need CMake 3.13 for installing targets from subdirectories
cmake_minimum_required(VERSION 3.13)

project(DatadogPHPComponents
  VERSION 0.1.0
  LANGUAGES C
)

option(BUILD_COMPONENTS_TESTING "Enable tests" OFF)
if (${BUILD_COMPONENTS_TESTING})
  # Tests uses the C++ testing framework Catch2
  enable_language(CXX)

  # The Catch2::Catch2 target has been available since 2.1.2
  # dd-trace-cpp uses Catch2 2.4 at this time, so we can require at least 2.4
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

add_subdirectory(string_view)
add_subdirectory(uuid)

add_library(DatadogPHPComponents INTERFACE)

target_link_libraries(DatadogPHPComponents
  INTERFACE DatadogPHPStringView DatadogPHPUUID
)

install(
  TARGETS DatadogPHPComponents DatadogPHPStringView DatadogPHPUUID
  EXPORT DatadogPHPComponentsTargets
  RUNTIME DESTINATION ${CMAKE_INSTALL_BINDIR}
  LIBRARY DESTINATION ${CMAKE_INSTALL_LIBDIR}
  INCLUDES DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}
)

install(EXPORT DatadogPHPComponentsTargets
  FILE DatadogPHPComponentsTargets.cmake
  NAMESPACE Datadog::PHP::
  DESTINATION ${CMAKE_INSTALL_LIBDIR}/cmake
)
