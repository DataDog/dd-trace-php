/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: 7875ffe7095e579e7ff76ece953fbbc1c9ff12f6 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_datadog_appsec_testing_convert_json, 0, 1, IS_ARRAY, 1)
	ZEND_ARG_TYPE_INFO(0, json, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_datadog_appsec_testing_convert_xml, 0, 2, IS_ARRAY, 1)
	ZEND_ARG_TYPE_INFO(0, xml, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, contentType, IS_STRING, 0)
ZEND_END_ARG_INFO()


ZEND_FUNCTION(datadog_appsec_testing_convert_json);
ZEND_FUNCTION(datadog_appsec_testing_convert_xml);


static const zend_function_entry ext_functions[] = {
	ZEND_NS_FALIAS("datadog\\appsec\\testing", convert_json, datadog_appsec_testing_convert_json, arginfo_datadog_appsec_testing_convert_json)
	ZEND_NS_FALIAS("datadog\\appsec\\testing", convert_xml, datadog_appsec_testing_convert_xml, arginfo_datadog_appsec_testing_convert_xml)
	ZEND_FE_END
};
