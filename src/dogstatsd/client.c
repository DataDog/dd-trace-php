#include "dogstatsd_client/client.h"

#include <stdio.h>
#include <string.h>
#include <sys/socket.h>
#include <unistd.h>

/* These functions are inline, but need to go in a translation unit somewhere
 * to avoid certain linker errors. The extern inline handles this.
 */
extern inline dogstatsd_client dogstatsd_client_default_ctor();
extern inline bool dogstatsd_client_is_default_client(dogstatsd_client client);
extern inline const char *dogstatsd_metric_type_to_str(dogstatsd_metric_t type);
extern inline const char *dogstatsd_client_status_to_str(
    dogstatsd_client_status status);

extern inline dogstatsd_client_status dogstatsd_client_count(
    dogstatsd_client *client, const char *metric, const char *value,
    const char *tags);

extern inline dogstatsd_client_status dogstatsd_client_gauge(
    dogstatsd_client *client, const char *metric, const char *value,
    const char *tags);

extern inline dogstatsd_client_status dogstatsd_client_histogram(
    dogstatsd_client *client, const char *metric, const char *value,
    const char *tags);

int dogstatsd_client_getaddrinfo(struct addrinfo **result, const char *host,
                                 const char *port) {
  struct addrinfo hints;
  hints.ai_family = AF_UNSPEC;
  hints.ai_socktype = SOCK_DGRAM;
  hints.ai_protocol = IPPROTO_UDP;
  hints.ai_flags = AI_NUMERICSERV;

  /* resolve the domain name into a list of addresses */
  return getaddrinfo(host, port, &hints, result);
}

dogstatsd_client dogstatsd_client_ctor(struct addrinfo *addrs, char *buffer,
                                       int buffer_len, const char *const_tags) {
  dogstatsd_client client = dogstatsd_client_default_ctor();

  if (!addrs) {
    return client;
  }
  client.addresslist = addrs;

  struct addrinfo *addr = NULL;
  if (!buffer || buffer_len < 0) {
    return client;
  }

  /* loop over all returned results and do inverse lookup */
  for (addr = client.addresslist; addr != NULL; addr = addr->ai_next) {
    if ((client.socket = socket(addr->ai_family, addr->ai_socktype,
                                addr->ai_protocol)) != -1) {
      break;
    }
  }

  if (!const_tags) {
    const_tags = "";
  }

  client.const_tags = const_tags;
  client.const_tags_len = strlen(const_tags);
  client.address = addr;
  client.msg_buffer = buffer;
  client.msg_buffer_len = buffer_len;

  return client;
}

void dogstatsd_client_dtor(dogstatsd_client *client) {
  if (!client) {
    return;
  }
  if (client->socket != -1) {
    close(client->socket);
    client->socket = -1;
  }
  if (client->addresslist) {
    freeaddrinfo(client->addresslist);
    client->addresslist = NULL;
  }
}

/* allowed metric types: c, g, ms, h, and s.
 * sample_rate must be between 0.0 and 1.0 (inclusive); if you are unsure then
 * specify 1.0.
 */
dogstatsd_client_status dogstatsd_client_metric_send(
    dogstatsd_client *client, const char *name, const char *value,
    dogstatsd_metric_t type, double sample_rate, const char *tags) {
  if (dogstatsd_client_is_default_client(*client)) {
    return DOGSTATSD_CLIENT_E_NO_CLIENT;
  }

  const char *typestr = dogstatsd_metric_type_to_str(type);
  if (!name || !value || !typestr || sample_rate < 0.0 || sample_rate > 1.0) {
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

  const char *format;
  int size;
  /* Omit the sample rate iff it is 1.0; a sample rate of 1.000000 causes issues
   * for the agent, for some reason
   */
  if (sample_rate != 1.0) {
    format = "%s:%s|%s|@%.6f%s%s%s%s";
    size = snprintf(client->msg_buffer, client->msg_buffer_len, format, name,
                    value, typestr, sample_rate, tags_prefix, tags,
                    tags_separator, client->const_tags);
  } else {
    format = "%s:%s|%s%s%s%s%s";
    size = snprintf(client->msg_buffer, client->msg_buffer_len, format, name,
                    value, typestr, tags_prefix, tags, tags_separator,
                    client->const_tags);
  }

  if (size < 0) {
    return DOGSTATSD_CLIENT_E_FORMATTING;
  }

  /* snprintf does not report the null byte in the length, so if it is size or
   * more then it is an error
   */
  if (size >= client->msg_buffer_len) {
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
