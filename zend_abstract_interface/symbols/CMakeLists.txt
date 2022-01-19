add_library(zai_symbols lookup.c call.c)

target_include_directories(
  zai_symbols PUBLIC $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                    $<INSTALL_INTERFACE:include>)

target_link_libraries(zai_symbols PUBLIC "${PHP_LIB}" Zai::Sandbox)

target_compile_features(zai_symbols PUBLIC c_std_99)

set_target_properties(zai_symbols PROPERTIES EXPORT_NAME Symbols
                                            VERSION ${PROJECT_VERSION})

add_library(Zai::Symbols ALIAS zai_symbols)

if(${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

install(
  FILES ${CMAKE_CURRENT_SOURCE_DIR}/symbols.h
  DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/symbols/)

install(
  DIRECTORY ${CMAKE_CURRENT_SOURCE_DIR}/api
  DESTINATION ${CMAKE_INSTAL_INCLUDEDIR}/symbols/api)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_symbols)

install(TARGETS zai_symbols EXPORT ZendAbstractInterfaceTargets)
