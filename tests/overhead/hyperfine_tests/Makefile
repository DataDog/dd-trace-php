
.PHONY: function_calls method_calls

all: method_calls function_calls

function_calls:
	@hyperfine \
		"php function_calls.php"\
		"php -dextension=ddtrace.so function_calls.php trace_function"\
		"php -dextension=ddtrace.so function_calls.php"

method_calls:
	@hyperfine \
		"php method_calls.php"\
		"php -dextension=ddtrace.so method_calls.php trace_method"\
		"php -dextension=ddtrace.so method_calls.php"
