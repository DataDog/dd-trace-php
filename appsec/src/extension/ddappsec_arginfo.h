/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: 12b545740a93f0ea5f4ecfdfc3a95fb144161bd2 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_datadog_appsec_is_enabled, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_datadog_appsec_push_addresses, 0, 1, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO(0, addresses, IS_ARRAY, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, rasp_rule, IS_STRING, 0, "\'\'")
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, rule_variant, IS_STRING, 0, "\'\'")
ZEND_END_ARG_INFO()

ZEND_FUNCTION(datadog_appsec_is_enabled);
ZEND_FUNCTION(datadog_appsec_push_addresses);

static const zend_function_entry ext_functions[] = {
	ZEND_RAW_FENTRY(ZEND_NS_NAME("datadog\\appsec", "is_enabled"), zif_datadog_appsec_is_enabled, arginfo_datadog_appsec_is_enabled, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("datadog\\appsec", "push_addresses"), zif_datadog_appsec_push_addresses, arginfo_datadog_appsec_push_addresses, 0, NULL, NULL)
	ZEND_FE_END
};
