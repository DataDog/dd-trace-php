#include "dogstatsd_client/client.h"

#include <stdio.h>
#include <string.h>
#include <sys/socket.h>
#include <unistd.h>

dogstatsd_client dogstatsd_client_default_ctor() {
  dogstatsd_client client = {
      .socket = -1,
      .address = NULL,
      .addresslist = NULL,
      .msg_buffer = NULL,
      .msg_buffer_len = 0,
      .const_tags = NULL,
      .const_tags_len = 0,
  };
  return client;
}

/* This function is inline, but it needs to go in a translation unit somewhere
 * to avoid certain linker errors. This line instructs the tooling it should
 * go here.
 */
extern inline int dogstatsd_client_is_default_client(dogstatsd_client *);

dogstatsd_client dogstatsd_client_ctor(const char *host, const char *port,
                                       char *buffer, int buffer_len,
                                       const char *const_tags) {
  if (!host || !port || !buffer || buffer_len < 0) {
    return dogstatsd_client_default_ctor();
  }

  struct addrinfo *result;
  struct addrinfo *res;
  int error;

  struct addrinfo hints;
  hints.ai_family = AF_UNSPEC;
  hints.ai_socktype = SOCK_DGRAM;
  hints.ai_protocol = IPPROTO_UDP;
  hints.ai_flags = AI_NUMERICSERV;

  /* resolve the domain name into a list of addresses */
  error = getaddrinfo(host, port, &hints, &result);
  if (error != 0) {
    if (error == EAI_SYSTEM) {
      perror("getaddrinfo");
    } else {
      fprintf(stderr, "error in getaddrinfo: %s\n", gai_strerror(error));
    }
    return dogstatsd_client_default_ctor();
  }

  int fd = -1;
  /* loop over all returned results and do inverse lookup */
  for (res = result; res != NULL; res = res->ai_next) {
    if ((fd = socket(res->ai_family, res->ai_socktype, res->ai_protocol)) !=
        -1) {
      break;
    }
  }

  if (res == NULL || fd == -1) {
    return dogstatsd_client_default_ctor();
  }

  if (!const_tags) {
    const_tags = "";
  }

  dogstatsd_client client = {
      .socket = fd,
      .addresslist = result,
      .address = res,
      .msg_buffer = buffer,
      .msg_buffer_len = buffer_len,
      .const_tags = const_tags,
      .const_tags_len = strlen(const_tags),
  };

  return client;
}

void dogstatsd_client_dtor(dogstatsd_client *client) {
  if (!client) {
    return;
  }
  if (client->socket != -1) {
    close(client->socket);
  }
  if (client->addresslist) {
    freeaddrinfo(client->addresslist);
  }
}

/* allowed metric types: c, g, ms, h, and s.
 * sample_rate must be between 0.0 and 1.0 (inclusive); if you are unsure then
 * specify 1.0.
 */
static dogstatsd_client_status _dogstatsd_client_send(
    dogstatsd_client *client, const char *name, const char *value,
    const char *type, float sample_rate, const char *tags) {
  if (dogstatsd_client_is_default_client(client)) {
    return DOGSTATSD_CLIENT_E_NO_CLIENT;
  }

  if (!name || !value || !type) {
    return DOGSTATSD_CLIENT_E_VALUE;
  }

  /* We need to concatenate all the strings together (without spaces, they
   * are there just to show how the format maps):
   *     metric : value | type |@ sample_rate |# tags ,  const_tags
   *     %s     : %s    | %s   |@ %f          %s %s   %s %s
   */

  if (!tags) {
    tags = "";
  }

  size_t tags_len = strlen(tags);
  size_t const_tags_len = client->const_tags_len;
  const char *tags_prefix = (tags_len + const_tags_len > 0) ? "|#" : "";
  const char *tags_separator = (tags_len > 0 && const_tags_len > 0) ? "," : "";

  const char *format = "%s:%s|%s|@%f%s%s%s%s";
  int size = snprintf(client->msg_buffer, client->msg_buffer_len, format, name,
                      value, type, sample_rate, tags_prefix, tags,
                      tags_separator, client->const_tags);

  if (size < 0) {
    return DOGSTATSD_CLIENT_E_FORMATTING;
  }

  /* snprintf does not report the null byte in the length, so if it is size or
   * more then it is an error
   */
  if (((size_t)size) >= client->msg_buffer_len) {
    return DOGSTATSD_CLIENT_E_TOO_LONG;
  }

  ssize_t send_status =
      sendto(client->socket, client->msg_buffer, size, MSG_DONTWAIT,
             client->address->ai_addr, client->address->ai_addrlen);

  if (send_status > -1) {
    return DOGSTATSD_CLIENT_OK;
  }

  return DOGSTATSD_CLIENT_EWRITE;
}

dogstatsd_client_status dogstatsd_client_count(dogstatsd_client *client,
                                               const char *metric,
                                               const char *value,
                                               const char *tags) {
  return _dogstatsd_client_send(client, metric, value, "c", 1.0f, tags);
}
