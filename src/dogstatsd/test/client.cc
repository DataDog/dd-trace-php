#include <dogstatsd_client/client.h>
#include <sys/socket.h>
#include <unistd.h>

#include <catch2/catch.hpp>
#include <thread>

// dummy server
struct dogstatsd_server {
  int sock;
  struct sockaddr_in addr;

  ~dogstatsd_server() { close(sock); }
};

dogstatsd_server dogstatsd_server_make() {
  int sock = -1;
  REQUIRE((sock = socket(AF_INET, SOCK_DGRAM, IPPROTO_UDP)) >= 0);

  struct sockaddr_in server = {0};
  socklen_t len = sizeof server;
  memset(&server, 0, sizeof server);
  server.sin_family = AF_INET;
  server.sin_addr.s_addr = INADDR_ANY;
  server.sin_port = 0;  // pick any open port

  REQUIRE(bind(sock, (struct sockaddr *)&server, len) > -1);

  // find out which port we were given
  REQUIRE(getsockname(sock, (struct sockaddr *)&server, &len) > -1);

  return dogstatsd_server{sock, server};
}

void dogstatsd_server_listen(dogstatsd_server *server, dogstatsd_client *client,
                             const char *expected_string) {
  socklen_t client_addr_size = sizeof(struct addrinfo);
  // 60 bytes for the IP header
  // 8 bytes for the UDP overhead
  int buffer_len = client->msg_buffer_len + 60 + 8;
  char *buffer = (char *)malloc(buffer_len);

  ssize_t bytes_received =
      recvfrom(server->sock, buffer, buffer_len, 0,
               (struct sockaddr *)&client->address, &client_addr_size);

  REQUIRE(bytes_received > -1);
  REQUIRE(bytes_received < buffer_len);

  buffer[bytes_received] = '\0';

  // this may be too brittle; if so, may need to parse the full string
  std::string actual_string = buffer;
  REQUIRE(actual_string == expected_string);

  free(buffer);
}

template <class Method>
static void _test_method(const char *expect, const char *metric,
                         const char *value, const char *tags,
                         const char *const_tags, Method method) {
  dogstatsd_server server = dogstatsd_server_make();

  char buf[DOGSTATSD_CLIENT_RECOMMENDED_MAX_MESSAGE_SIZE];
  size_t len = DOGSTATSD_CLIENT_RECOMMENDED_MAX_MESSAGE_SIZE;
  char host[NI_MAXHOST];
  char port[NI_MAXSERV];

  getnameinfo((sockaddr *)&server.addr, server.addr.sin_len, host, NI_MAXHOST,
              port, NI_MAXSERV, NI_NUMERICHOST | NI_NUMERICSERV);

  struct addrinfo *addrs = nullptr;
  REQUIRE(!dogstatsd_client_getaddrinfo(&addrs, host, port));
  dogstatsd_client client = dogstatsd_client_ctor(addrs, buf, len, const_tags);
  REQUIRE(!dogstatsd_client_is_default_client(client));

  // start a thread for the server
  std::thread server_thread{dogstatsd_server_listen, &server, &client, expect};

  dogstatsd_client_status status = method(&client, metric, value, tags);
  REQUIRE(status == DOGSTATSD_CLIENT_OK);

  server_thread.join();

  dogstatsd_client_dtor(&client);
}

void _test_count(const char *expect, const char *metric, const char *value,
                 const char *tags, const char *const_tags) {
  _test_method(expect, metric, value, tags, const_tags,
               [](dogstatsd_client *client, const char *metric,
                  const char *value, const char *tags) {
                 return dogstatsd_client_count(client, metric, value, tags);
               });
}

void _test_gauge(const char *expect, const char *metric, const char *value,
                 const char *tags, const char *const_tags) {
  _test_method(expect, metric, value, tags, const_tags,
               [](dogstatsd_client *client, const char *metric,
                  const char *value, const char *tags) {
                 return dogstatsd_client_gauge(client, metric, value, tags);
               });
}

void _test_histogram(const char *expect, const char *metric, const char *value,
                     const char *tags, const char *const_tags) {
  _test_method(expect, metric, value, tags, const_tags,
               [](dogstatsd_client *client, const char *metric,
                  const char *value, const char *tags) {
                 return dogstatsd_client_histogram(client, metric, value, tags);
               });
}

void _test_metric(const char *expect, const char *metric, const char *value,
                  dogstatsd_metric_t type, double sample_rate, const char *tags,
                  const char *const_tags) {
  auto closure = [type, sample_rate](dogstatsd_client *client,
                                     const char *metric, const char *value,
                                     const char *tags) {
    return dogstatsd_client_metric_send(client, metric, value, type,
                                        sample_rate, tags);
  };
  _test_method(expect, metric, value, tags, const_tags, closure);
}

TEST_CASE("count -tags -const_tags", "[dogstatsd_client]") {
  const char *expect = "page.views:1|c";
  const char *metric = "page.views";
  dogstatsd_metric_t type = DOGSTATSD_METRIC_COUNT;

  _test_count(expect, metric, "1", nullptr, nullptr);
  _test_metric(expect, metric, "1", type, 1.0, nullptr, nullptr);
}

TEST_CASE("count -tags +const_tags", "[dogstatsd_client]") {
  const char *expect = "page.views:1|c|#hello:world";
  const char *metric = "page.views";
  dogstatsd_metric_t type = DOGSTATSD_METRIC_COUNT;
  const char *const_tags = "hello:world";

  _test_count(expect, metric, "1", nullptr, const_tags);
  _test_metric(expect, metric, "1", type, 1.0, nullptr, const_tags);
}

TEST_CASE("count +tags -const_tags", "[dogstatsd_client]") {
  const char *expect = "page.views:1|c|#lang:c";
  const char *metric = "page.views";
  dogstatsd_metric_t type = DOGSTATSD_METRIC_COUNT;
  const char *tags = "lang:c";

  _test_count(expect, metric, "1", tags, nullptr);
  _test_metric(expect, metric, "1", type, 1.0, tags, nullptr);
}

TEST_CASE("count +tags +const_tags", "[dogstatsd_client]") {
  const char *expect = "page.views:1|c|#lang:c,hello:world";
  const char *metric = "page.views";
  dogstatsd_metric_t type = DOGSTATSD_METRIC_COUNT;
  const char *tags = "lang:c";
  const char *const_tags = "hello:world";

  _test_count(expect, metric, "1", tags, const_tags);
  _test_metric(expect, metric, "1", type, 1.0, tags, const_tags);
}

TEST_CASE("gauge +tags +const_tags", "[dogstatsd_client]") {
  const char *expect = "fuel.level:0.5|g|#lang:c,hello:world";
  const char *metric = "fuel.level";
  dogstatsd_metric_t type = DOGSTATSD_METRIC_GAUGE;
  const char *tags = "lang:c";
  const char *const_tags = "hello:world";

  _test_gauge(expect, metric, "0.5", tags, const_tags);
  _test_metric(expect, metric, "0.5", type, 1.0, tags, const_tags);
}

TEST_CASE("histogram +tags +const_tags", "[dogstatsd_client]") {
  const char *expect = "song.length:240|h|#lang:c,hello:world";
  const char *metric = "song.length";
  dogstatsd_metric_t type = DOGSTATSD_METRIC_HISTOGRAM;
  const char *tags = "lang:c";
  const char *const_tags = "hello:world";

  _test_histogram(expect, metric, "240", tags, const_tags);
  _test_metric(expect, metric, "240", type, 1.0, tags, const_tags);
}

TEST_CASE("sample_rate -tags -const_tags", "[dogstatsd_client]") {
  const char *expect = "song.length:240|h|@0.500000";
  const char *metric = "song.length";
  dogstatsd_metric_t type = DOGSTATSD_METRIC_HISTOGRAM;
  _test_metric(expect, metric, "240", type, 0.5, nullptr, nullptr);
}

// todo: test sending message that's too large
// todo: test configuring client with lens of 0 and < 0.
// todo: test an out of range sample rate returns DOGSTATSD_CLIENT_E_VALUE
