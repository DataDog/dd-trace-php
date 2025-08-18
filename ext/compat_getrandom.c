// Minimal getrandom(2) compatibility for older libcs without the symbol
#include <sys/types.h>
#include <fcntl.h>
#include <unistd.h>
#include <errno.h>

ssize_t getrandom(void *buf, size_t buflen, unsigned int flags) {
    (void)flags;
    int fd = open("/dev/urandom", O_RDONLY);
    if (fd < 0) {
        return -1;
    }
    size_t bytes_read = 0;
    while (bytes_read < buflen) {
        ssize_t r = read(fd, (char *)buf + bytes_read, buflen - bytes_read);
        if (r < 0) {
            if (errno == EINTR) {
                continue;
            }
            close(fd);
            return -1;
        }
        bytes_read += (size_t)r;
    }
    close(fd);
    return (ssize_t)bytes_read;
}

