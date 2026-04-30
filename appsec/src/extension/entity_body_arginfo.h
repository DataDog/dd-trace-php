/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: dfa1f0081bab0c798625353df5966ae21e7c4b89 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_datadog_appsec_convert_xml, 0, 2, IS_ARRAY, 1)
	ZEND_ARG_TYPE_INFO(0, xml, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, contentType, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_datadog_appsec_convert_json, 0, 1, IS_MIXED, 1)
	ZEND_ARG_TYPE_INFO(0, json, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, maxDepth, IS_LONG, 0, "30")
ZEND_END_ARG_INFO()


ZEND_FUNCTION(datadog_appsec_convert_xml);
ZEND_FUNCTION(datadog_appsec_convert_json);


static const zend_function_entry ext_functions[] = {
	ZEND_NS_FALIAS("datadog\\appsec", convert_xml, datadog_appsec_convert_xml, arginfo_datadog_appsec_convert_xml)
	ZEND_NS_FALIAS("datadog\\appsec", convert_json, datadog_appsec_convert_json, arginfo_datadog_appsec_convert_json)
	ZEND_FE_END
};
