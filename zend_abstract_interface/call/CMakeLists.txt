add_library(zai_call call.c)

target_include_directories(
  zai_call PUBLIC $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                    $<INSTALL_INTERFACE:include>)

target_link_libraries(zai_call PUBLIC "${PHP_LIB}" Zai::Sandbox)

target_compile_features(zai_call PUBLIC c_std_99)

set_target_properties(zai_call PROPERTIES EXPORT_NAME Call
                                            VERSION ${PROJECT_VERSION})

add_library(Zai::Call ALIAS zai_call)

if(${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

install(
  FILES ${CMAKE_CURRENT_SOURCE_DIR}/call.h
  DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/call/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_call)

install(TARGETS zai_call EXPORT ZendAbstractInterfaceTargets)
