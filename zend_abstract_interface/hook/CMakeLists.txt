add_library(zai_hook hook.c line_hooks.c)

target_include_directories(
  zai_hook PUBLIC $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                    $<INSTALL_INTERFACE:include>)

target_compile_features(zai_hook PUBLIC c_std_99)

# JitUtils is needed on every version now: hook.c reaches OPcache's exported symbols through it.
target_link_libraries(zai_hook PUBLIC Tea::Php Zai::String Zai::JitUtils)

set_target_properties(zai_hook PROPERTIES EXPORT_NAME Hook
                                            VERSION ${PROJECT_VERSION})

add_library(Zai::Hook ALIAS zai_hook)

if(${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

install(
  FILES ${CMAKE_CURRENT_SOURCE_DIR}/hook.h
        ${CMAKE_CURRENT_SOURCE_DIR}/line_hooks.h
  DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/hook/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_hook)

install(TARGETS zai_hook EXPORT ZendAbstractInterfaceTargets)
