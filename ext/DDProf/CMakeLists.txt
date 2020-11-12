cmake_minimum_required(VERSION 3.10)
project(DDProf LANGUAGES CXX)

add_library(DDProf STATIC src/string_table.cc)
target_compile_features(DDProf PUBLIC cxx_std_14)

target_include_directories(DDProf PUBLIC
  $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}/include>
  $<INSTALL_INTERFACE:include>
)

#[[ We want to be able to use the namespaced name everywhere, including in this
    project's tests; this is a pattern described in the talk Effective CMake
    that allows it.
#]]
add_library(DDProf::DDProf ALIAS DDProf)

add_executable(profile profile.cc)
target_link_libraries(profile PUBLIC PProf)
target_include_directories(profile PUBLIC
  $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}/include>
  $<INSTALL_INTERFACE:include>
  )


option(BUILD_TESTING "Enable tests" ON)
include(CTest)
if (${BUILD_TESTING})
    enable_testing()
    add_subdirectory(test)
endif()

