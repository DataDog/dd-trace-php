// C smoke test of the static library: allocate a cache in an anonymous
// shared mapping, register participants on several threads, insert and look
// up, unregister, free. Build and run (from the repository root):
//
//   cargo build -p shm_gen_cache --profile tracer-release
//   cc -O2 -std=c11 -pthread -I shm_gen_cache shm_gen_cache/tests/c_smoke.c
//     target/tracer-release/libshm_gen_cache.a -lm -ldl -o /tmp/sgc_c_smoke
//   (one command)
//   /tmp/sgc_c_smoke

#include <inttypes.h>
#include <pthread.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "shm_gen_cache.h"

#define THREADS 8
#define KEYS 20000
#define MAX_VALUE 64

static ddog_sgc_Cache *cache;

#define CHECK(cond)                                                       \
  do {                                                                    \
    if (!(cond)) {                                                        \
      fprintf(stderr, "%s:%d: check failed: %s\n", __FILE__, __LINE__, #cond); \
      exit(1);                                                            \
    }                                                                     \
  } while (0)

static uint64_t hash_of(uint64_t k) {
  uint64_t z = k + 0x9e3779b97f4a7c15ULL;
  z = (z ^ (z >> 30)) * 0xbf58476d1ce4e5b9ULL;
  z = (z ^ (z >> 27)) * 0x94d049bb133111ebULL;
  return z ^ (z >> 31);
}

static size_t value_len_of(uint64_t k) { return (size_t)(k * 13 % (MAX_VALUE + 1)); }

static void fill_value(uint64_t k, uint8_t *out) {
  for (size_t i = 0; i < value_len_of(k); i++) out[i] = (uint8_t)((k * 31 + i * 7) % 251);
}

static void *worker(void *arg) {
  uint64_t t = (uint64_t)(uintptr_t)arg;
  ddog_sgc_Participant *p = NULL;
  CHECK(ddog_sgc_participant_register(cache, &p) == DDOG_SGC_STATUS_OK);
  uint64_t out[MAX_VALUE / 8];
  uint8_t expected[MAX_VALUE];
  unsigned fresh = 0;
  for (uint64_t i = 0; i < KEYS; i++) {
    uint64_t k = t * KEYS + i;
    fill_value(k, expected);
    ddog_sgc_Status s = ddog_sgc_insert(p, hash_of(k), (const uint8_t *)&k, sizeof k, expected, value_len_of(k));
    CHECK(s == DDOG_SGC_STATUS_OK || s == DDOG_SGC_STATUS_ROTATION_OWNER_TIMEOUT ||
          s == DDOG_SGC_STATUS_ARENA_REUSE_TIMEOUT);
    uint64_t probes[2] = {k, k / 2};
    for (int j = 0; j < 2; j++) {
      uint64_t q = probes[j];
      size_t len = 0;
      s = ddog_sgc_lookup(p, hash_of(q), (const uint8_t *)&q, sizeof q, out, MAX_VALUE, &len);
      if (s == DDOG_SGC_STATUS_OK) {
        fill_value(q, expected);
        CHECK(len == value_len_of(q) && memcmp(out, expected, len) == 0);
        if (j == 0) fresh++;
      } else {
        CHECK(s == DDOG_SGC_STATUS_MISS || s == DDOG_SGC_STATUS_ROTATION_OWNER_TIMEOUT ||
              s == DDOG_SGC_STATUS_ARENA_REUSE_TIMEOUT);
      }
    }
  }
  CHECK(fresh * 10 >= KEYS * 9);
  ddog_sgc_participant_unregister(p);
  return NULL;
}

int main(void) {
  ddog_sgc_Config config = {
      .participant_capacity = 16,
      .bucket_count = 8192,
      .max_key_size = 16,
      .max_value_size = MAX_VALUE,
  };
  CHECK(ddog_sgc_cache_new(&config, &cache) == DDOG_SGC_STATUS_OK);
  pthread_t threads[THREADS];
  for (uintptr_t t = 0; t < THREADS; t++) CHECK(pthread_create(&threads[t], NULL, worker, (void *)t) == 0);
  for (int t = 0; t < THREADS; t++) CHECK(pthread_join(threads[t], NULL) == 0);

  ddog_sgc_Participant *p = NULL;
  CHECK(ddog_sgc_participant_register(cache, &p) == DDOG_SGC_STATUS_OK);
  uint64_t out[MAX_VALUE / 8];
  size_t len = 0;
  CHECK(ddog_sgc_insert(p, 42, (const uint8_t *)"key", 3, (const uint8_t *)"value", 5) == DDOG_SGC_STATUS_OK);
  CHECK(ddog_sgc_lookup(p, 42, (const uint8_t *)"key", 3, out, sizeof out, &len) == DDOG_SGC_STATUS_OK);
  CHECK(len == 5 && memcmp(out, "value", 5) == 0);
  CHECK(ddog_sgc_lookup(p, 43, (const uint8_t *)"kez", 3, out, sizeof out, &len) == DDOG_SGC_STATUS_MISS);
  CHECK(ddog_sgc_lookup(p, 42, (const uint8_t *)"key", 3, out, 4, &len) == DDOG_SGC_STATUS_INSUFFICIENT_CAPACITY);
  ddog_sgc_participant_unregister(p);
  ddog_sgc_cache_free(cache);
  puts("c_smoke: ok");
  return 0;
}
