#include <stdio.h>
#include <string.h>

int main() {
    int buffer_len = 15;
    int buffer_step = 0;
    char buffer[buffer_len];
    const int WAITING_FILE = 0;
    const int FILE = 1;
    const int LINE_NUMBER = 2;
    int state_machine = WAITING_FILE;
    char *full_stack =
        "\nStack trace:\n#0 /home/circleci/app/tests/Integration/ErrorReporting/scripts/trigger_exception.php(15): "
        "aaaa('secret')\n#1 /home/circleci/app/tests/Integration/ErrorReporting/scripts/script.php(3): require()\n";

    // Searching for '#0 ', which is the beginning of the first line of the stack trace.
    strncpy(buffer, full_stack, buffer_len);
    for (int char_index = 0; char_index < buffer_len - 1; char_index++) {
        printf(">>> %c\n", buffer[char_index]);
    }
    return 0;
}
