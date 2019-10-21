#ifndef DOGSTATSD_CLIENT_H
#define DOGSTATSD_CLIENT_H

#include <netdb.h>

/* This describes a simple interface to communicate with dogstatsd. It only
 * implements the portions of the interface that the PHP tracer needs. If it
 * gets enough features and there is interest from another project, we could
 * pull it out and release it as its own project.
 */

#if __cplusplus
extern "C" {
#endif

typedef struct {
  int socket;                    // closed on dtor
  struct addrinfo *address;      // freed on dtor as part of addresslist
  struct addrinfo *addresslist;  // freed on dtor
  char *msg_buffer;              // NOT freed on dtor
  size_t msg_buffer_len;
  const char *const_tags;  // NOT freed on dtor
  size_t const_tags_len;
} dogstatsd_client;

typedef enum {
  // This doesn't mean delivered; just that nothing went visibly wrong.
  DOGSTATSD_CLIENT_OK = 0,

  // errors:
  DOGSTATSD_CLIENT_E_NO_CLIENT,
  DOGSTATSD_CLIENT_E_VALUE,
  DOGSTATSD_CLIENT_E_TOO_LONG,
  DOGSTATSD_CLIENT_E_FORMATTING,
  DOGSTATSD_CLIENT_EWRITE,
} dogstatsd_client_status;

/* A typical IPv4 header is 20 bytes, but can be up to 60 bytes.
 * The UDP header is 8 bytes.
 * The minimum maximum reassembly buffer size required by RFC 1122 is 576.
 * 576 - 60 - 8 = 508.
 *
 * If we consider IPv6, the situation is different. IPv6 packets cannot be
 * fragmented, and the minimum MTU is 1280. Most implementations seem to use
 * 1024 for the IPv6 buffer.
 *
 * Ethernet has an MTU 1500.
 *
 * The official Java dogstatsd client uses 1400. Given this, I am presuming
 * that most hardware in use today is capable of supporting IPv6, and out of
 * implementation simplicity they support the IPv6 sizes on IPv4 too, and
 * chose the IPv6 numbers.
 */
#define DOGSTATSD_CLIENT_RECOMMENDED_MAX_MESSAGE_SIZE 1024

// Creates a client whose operations will fail with E_NO_CLIENT
dogstatsd_client dogstatsd_client_default_ctor();

inline int dogstatsd_client_is_default_client(dogstatsd_client *client) {
  return !client || client->socket == -1;
}

/* If the client fails to open a socket to the host and port, it will
 * create a default client.
 */
dogstatsd_client dogstatsd_client_ctor(const char *host, const char *port,
                                       char *buffer, int buffer_len,
                                       const char *const_tags);

// Uses sample_rate of 1.0
dogstatsd_client_status dogstatsd_client_count(dogstatsd_client *client,
                                               const char *metric,
                                               const char *value,
                                               const char *tags);

void dogstatsd_client_dtor(dogstatsd_client *client);

#if __cplusplus
}
#endif

#endif  // DOGSTATSD_CLIENT_H
