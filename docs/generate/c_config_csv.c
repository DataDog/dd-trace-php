#define DD_CONFIGURATION_RENDER_H  // do not render any of the configuration
#define DD_INTERNAL_CONFIGURATION // do not print internal configuration options
#include <stdio.h>

#include "configuration.h"

#define STR1(x) #x
#define STR(x) STR1(x)
#define ESCAPE_DEFINE(x) "\"" STR(x) "\""
#define ESCAPE(x) "\"" x "\""

#define RENDER(type, env, def, description) printf("%s, %s, %s, %s\n", env, ESCAPE_DEFINE(type), def, description);

#undef TRUE
#undef FALSE
#define TRUE "true"
#define FALSE "false"
#define CHAR(func, env, def, description) RENDER(string, env, def, ESCAPE(description))
#define INT(func, env, def, description) RENDER(numeric, env, ESCAPE_DEFINE(def), ESCAPE(description))
#define BOOL(func, env, def, description) RENDER(boolean, env, def, ESCAPE(description))

int main(int argc, char **argv) {
    printf("Environment variable, Type, Default value, Description\n");
    DD_CONFIGURATION
}
