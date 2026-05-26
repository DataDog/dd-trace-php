/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: cf49b514da3ab250e03685f2cabc7ed20575bfa7 */

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_datadog_appsec_track_user_signup_event, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, user_id, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, metadata, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

#define arginfo_datadog_appsec_track_user_login_success_event arginfo_datadog_appsec_track_user_signup_event

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_datadog_appsec_track_user_login_failure_event, 0, 2, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, user_id, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, exists, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, metadata, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

#define arginfo_datadog_appsec_track_authenticated_user_event arginfo_datadog_appsec_track_user_signup_event

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_datadog_appsec_track_custom_event, 0, 1, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, event_name, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, metadata, IS_ARRAY, 0, "[]")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_datadog_appsec_internal_track_user_signup_event_automated, 0, 3, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, framework, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, user_login, IS_STRING, 1)
	ZEND_ARG_TYPE_INFO(0, user_id, IS_STRING, 1)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, metadata, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

#define arginfo_datadog_appsec_internal_track_user_login_success_event_automated arginfo_datadog_appsec_internal_track_user_signup_event_automated

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_datadog_appsec_internal_track_user_login_failure_event_automated, 0, 4, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, framework, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, user_login, IS_STRING, 1)
	ZEND_ARG_TYPE_INFO(0, user_id, IS_STRING, 1)
	ZEND_ARG_TYPE_INFO(0, exists, _IS_BOOL, 0)
	ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, metadata, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_datadog_appsec_internal_track_authenticated_user_event_automated, 0, 2, IS_VOID, 0)
	ZEND_ARG_TYPE_INFO(0, framework, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, user_id, IS_STRING, 1)
ZEND_END_ARG_INFO()

ZEND_FUNCTION(datadog_appsec_track_user_signup_event);
ZEND_FUNCTION(datadog_appsec_track_user_login_success_event);
ZEND_FUNCTION(datadog_appsec_track_user_login_failure_event);
ZEND_FUNCTION(datadog_appsec_track_authenticated_user_event);
ZEND_FUNCTION(datadog_appsec_track_custom_event);
ZEND_FUNCTION(datadog_appsec_internal_track_user_signup_event_automated);
ZEND_FUNCTION(datadog_appsec_internal_track_user_login_success_event_automated);
ZEND_FUNCTION(datadog_appsec_internal_track_user_login_failure_event_automated);
ZEND_FUNCTION(datadog_appsec_internal_track_authenticated_user_event_automated);

static const zend_function_entry ext_functions[] = {
	ZEND_RAW_FENTRY(ZEND_NS_NAME("datadog\\appsec", "track_user_signup_event"), zif_datadog_appsec_track_user_signup_event, arginfo_datadog_appsec_track_user_signup_event, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("datadog\\appsec", "track_user_login_success_event"), zif_datadog_appsec_track_user_login_success_event, arginfo_datadog_appsec_track_user_login_success_event, ZEND_ACC_DEPRECATED, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("datadog\\appsec", "track_user_login_failure_event"), zif_datadog_appsec_track_user_login_failure_event, arginfo_datadog_appsec_track_user_login_failure_event, ZEND_ACC_DEPRECATED, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("datadog\\appsec", "track_authenticated_user_event"), zif_datadog_appsec_track_authenticated_user_event, arginfo_datadog_appsec_track_authenticated_user_event, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("datadog\\appsec", "track_custom_event"), zif_datadog_appsec_track_custom_event, arginfo_datadog_appsec_track_custom_event, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("datadog\\appsec\\internal", "track_user_signup_event_automated"), zif_datadog_appsec_internal_track_user_signup_event_automated, arginfo_datadog_appsec_internal_track_user_signup_event_automated, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("datadog\\appsec\\internal", "track_user_login_success_event_automated"), zif_datadog_appsec_internal_track_user_login_success_event_automated, arginfo_datadog_appsec_internal_track_user_login_success_event_automated, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("datadog\\appsec\\internal", "track_user_login_failure_event_automated"), zif_datadog_appsec_internal_track_user_login_failure_event_automated, arginfo_datadog_appsec_internal_track_user_login_failure_event_automated, 0, NULL, NULL)
	ZEND_RAW_FENTRY(ZEND_NS_NAME("datadog\\appsec\\internal", "track_authenticated_user_event_automated"), zif_datadog_appsec_internal_track_authenticated_user_event_automated, arginfo_datadog_appsec_internal_track_authenticated_user_event_automated, 0, NULL, NULL)
	ZEND_FE_END
};
