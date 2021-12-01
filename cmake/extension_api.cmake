function(find_extension_api var)
	find_program(PHP_CONFIG php-config)
	if(PHP_CONFIG STREQUAL PHP_CONFIG-NOTFOUND)
		message(FATAL_ERROR "Cannot find php-config, either set PHP_CONFIG or make it discoverable")
	endif()

	execute_process(
		COMMAND bash -c "basename \"$('${PHP_CONFIG}' --extension-dir)\""
		RESULT_VARIABLE PHP_CONFIG_ZEND_API_RESULT
		OUTPUT_VARIABLE PHP_CONFIG_ZEND_API
		ERROR_VARIABLE PHP_CONFIG_ZEND_API_ERR
		OUTPUT_STRIP_TRAILING_WHITESPACE
		)
	if(NOT "${PHP_CONFIG_ZEND_API_RESULT}" STREQUAL "0")
		message(FATAL_ERROR "Error obtaining zend API spec: ${PHP_CONFIG_ZEND_API_ERR}")
	endif()

	set(${var} ${PHP_CONFIG_ZEND_API} PARENT_SCOPE)
endfunction()
