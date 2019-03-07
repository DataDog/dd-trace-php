
extern zend_class_entry *ddtrace_greeting_ce;

ZEPHIR_INIT_CLASS(DDTrace_Greeting);

PHP_METHOD(DDTrace_Greeting, say);

ZEPHIR_INIT_FUNCS(ddtrace_greeting_method_entry) {
	PHP_ME(DDTrace_Greeting, say, NULL, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	PHP_FE_END
};
