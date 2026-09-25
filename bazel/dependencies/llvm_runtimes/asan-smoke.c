#include <stdlib.h>
#include <string.h>

int main(int argc, char **argv) {
    if (argc != 2) return 2;

    char *allocation = (char *)malloc(32);
    if (!allocation) return 3;
    memset(allocation, 0x2a, 32);

    if (strcmp(argv[1], "clean") == 0) {
        int result = allocation[31] == 0x2a ? 0 : 4;
        free(allocation);
        return result;
    }
    if (strcmp(argv[1], "overflow") == 0) {
        volatile size_t outside = 32;
        allocation[outside] = 1;
        free(allocation);
        return 5;
    }

    free(allocation);
    return 6;
}
