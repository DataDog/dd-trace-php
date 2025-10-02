/* This is a generated file, edit ddappsec.stub.php instead.
 * Stub hash: b2ea3c1943fa54f01993f3fe384128967051f2cc */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    arginfo_datadog_appsec_is_enabled, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

#define arginfo_datadog_appsec_is_fully_disabled                               \
    arginfo_datadog_appsec_is_enabled

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    arginfo_datadog_appsec_push_addresses, 0, 1, _IS_BOOL, 0)
ZEND_ARG_TYPE_INFO(0, addresses, IS_ARRAY, 0)
ZEND_ARG_TYPE_MASK(0, rasp_rule, MAY_BE_ARRAY | MAY_BE_STRING, "\'\'")
ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, rule_variant, IS_STRING, 0, "\'\'")
ZEND_END_ARG_INFO()

#define arginfo_datadog_appsec_should_send_downstream_bodies                   \
    arginfo_datadog_appsec_is_enabled

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(
    arginfo_datadog_appsec_fflush_stdiocast, 0, 1, _IS_BOOL, 0)
ZEND_ARG_INFO(0, stream)
ZEND_END_ARG_INFO()

ZEND_FUNCTION(datadog_appsec_is_enabled);
ZEND_FUNCTION(datadog_appsec_is_fully_disabled);
ZEND_FUNCTION(datadog_appsec_push_addresses);
ZEND_FUNCTION(datadog_appsec_should_send_downstream_bodies);
ZEND_FUNCTION(datadog_appsec_fflush_stdiocast);

static const zend_function_entry ext_functions[] = {ZEND_RAW_FENTRY(
    ZEND_NS_NAME("datadog\\appsec", "is_enabled"),
    zif_datadog_appsec_is_enabled, arginfo_datadog_appsec_is_enabled, 0, NULL,
    NULL) ZEND_RAW_FENTRY(ZEND_NS_NAME("datadog\\appsec", "is_fully_disabled"),
    zif_datadog_appsec_is_fully_disabled,
    arginfo_datadog_appsec_is_fully_disabled, 0, NULL,
    NULL) ZEND_RAW_FENTRY(ZEND_NS_NAME("datadog\\appsec", "push_addresses"),
    zif_datadog_appsec_push_addresses, arginfo_datadog_appsec_push_addresses, 0,
    NULL, NULL) ZEND_RAW_FENTRY(ZEND_NS_NAME("datadog\\appsec",
                                    "should_send_downstream_bodies"),
    zif_datadog_appsec_should_send_downstream_bodies,
    arginfo_datadog_appsec_should_send_downstream_bodies, 0, NULL,
    NULL) ZEND_RAW_FENTRY(ZEND_NS_NAME("datadog\\appsec", "fflush_stdiocast"),
    zif_datadog_appsec_fflush_stdiocast,
    arginfo_datadog_appsec_fflush_stdiocast, 0, NULL, NULL) ZEND_FE_END};
