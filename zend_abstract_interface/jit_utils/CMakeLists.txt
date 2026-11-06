if(PhpConfig_VERNUM GREATER_EQUAL "80000")
  add_library(zai_jit_utils opcache_symbols.c jit_blacklist.c)
else()
  add_library(zai_jit_utils opcache_symbols.c)
endif()

target_include_directories(
  zai_jit_utils PUBLIC $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                    $<INSTALL_INTERFACE:include>)

target_compile_features(zai_jit_utils PUBLIC c_std_99)

target_link_libraries(zai_jit_utils PUBLIC Tea::Php dl)

set_target_properties(zai_jit_utils PROPERTIES EXPORT_NAME JitUtils
                                            VERSION ${PROJECT_VERSION})

add_library(Zai::JitUtils ALIAS zai_jit_utils)

install(
  FILES ${CMAKE_CURRENT_SOURCE_DIR}/jit_blacklist.h
        ${CMAKE_CURRENT_SOURCE_DIR}/is_mapped.h
        ${CMAKE_CURRENT_SOURCE_DIR}/opcache_symbols.h
  DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/jit_utils/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_jit_utils)

install(TARGETS zai_jit_utils EXPORT ZendAbstractInterfaceTargets)
