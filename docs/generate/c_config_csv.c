#define DD_CONFIGURATION_REDER_H  // do not render any of the configuration
#include <stdio.h>

#include "configuration.h"

#define STR1(x) #x
#define STR(x) STR1(x)
#define ESCAPE(x) "\"" STR(x) "\""

#define RENDER(type, env, def, description) printf("%s, %s, %s, %s\n", env, ESCAPE(type), def, description);

#define TRUE "true"
#define FALSE "false"
#define CHAR(func, env, def, description) RENDER(string, env, def, description)
#define INT(func, env, def, description) RENDER(numeric, env, ESCAPE(def), description)
#define BOOL(func, env, def, description) RENDER(boolean, env, def, description)

int main(int argc, char **argv) {
    printf("Environment variable, Type, Default value, Description\n");
    DD_CONFIGURATION
}
