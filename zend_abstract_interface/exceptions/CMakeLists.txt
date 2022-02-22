if(PHP_VERSION_DIRECTORY STREQUAL "php7" OR PHP_VERSION_DIRECTORY STREQUAL "php8")
  set(PHP_EXCEPTIONS_VERSION_DIRECTORY "php7-8")
else()
  set(PHP_EXCEPTIONS_VERSION_DIRECTORY "${PHP_VERSION_DIRECTORY}")
endif()

add_library(zai_exceptions "${PHP_EXCEPTIONS_VERSION_DIRECTORY}/exceptions.c")

target_include_directories(zai_exceptions PUBLIC
                                          $<BUILD_INTERFACE:${PROJECT_SOURCE_DIR}>
                                          $<INSTALL_INTERFACE:include>)

target_compile_features(zai_exceptions PUBLIC c_std_99)

target_link_libraries(zai_exceptions PUBLIC "${PHP_LIB}" Zai::Symbols)

set_target_properties(zai_exceptions PROPERTIES
                                     EXPORT_NAME exceptions
                                     VERSION ${PROJECT_VERSION})

add_library(Zai::Exceptions ALIAS zai_exceptions)

if (${BUILD_ZAI_TESTING})
  add_subdirectory(tests)
endif()

# This copies the include files when `install` is ran
install(FILES ${CMAKE_CURRENT_SOURCE_DIR}/exceptions.h ${CMAKE_CURRENT_SOURCE_DIR}/exceptions_common.h
        DESTINATION ${CMAKE_INSTALL_INCLUDEDIR}/exceptions/)

target_link_libraries(zai_zend_abstract_interface INTERFACE zai_exceptions)

install(TARGETS zai_exceptions EXPORT ZendAbstractInterfaceTargets)
