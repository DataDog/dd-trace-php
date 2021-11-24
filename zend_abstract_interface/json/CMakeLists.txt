add_library(zai_json json.c)

target_include_directories(
  zai_json PUBLIC $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                    $<INSTALL_INTERFACE:include>)

target_compile_features(zai_json PUBLIC c_std_99)

target_link_libraries(zai_json PUBLIC "${PHP_LIB}" dl)

set_target_properties(zai_json PROPERTIES EXPORT_NAME Json
                                            VERSION ${PROJECT_VERSION})

add_library(Zai::Json ALIAS zai_json)

if(${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

install(
  FILES ${CMAKE_CURRENT_SOURCE_DIR}/json.h
  DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/json/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_json)

install(TARGETS zai_json EXPORT ZendAbstractInterfaceTargets)
