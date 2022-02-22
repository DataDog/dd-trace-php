add_library(zai_hook hook.c)

target_include_directories(
  zai_hook PUBLIC $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                    $<INSTALL_INTERFACE:include>)

target_compile_features(zai_hook PUBLIC c_std_99)

target_link_libraries(zai_hook PUBLIC "${PHP_LIB}" Zai::Value Zai::Symbols)

set_target_properties(zai_hook PROPERTIES EXPORT_NAME Hook
                                            VERSION ${PROJECT_VERSION})

add_library(Zai::Hook ALIAS zai_hook)

if(${BUILD_ZAI_TESTING})
  if (PHP_VERSION_ID GREATER_EQUAL 50500)
      add_subdirectory(tests)
  endif()
endif()

install(
  FILES ${CMAKE_CURRENT_SOURCE_DIR}/hook.h
  DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/hook/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_hook)

install(TARGETS zai_hook EXPORT ZendAbstractInterfaceTargets)
