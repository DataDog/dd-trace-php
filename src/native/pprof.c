#include <fcntl.h>
#include <stdbool.h>
#include <stdio.h>
#include <sys/stat.h>
#include <sys/time.h>
#include <sys/types.h>
#include <unistd.h>
#include <zlib.h>

#include "pprof.h"
#include "string_table.h"

#ifndef DBG_PRINT_ENABLE
#define DBG_PRINT_ENABLE 0
#endif
#define DBG_PRINT(...)                                                         \
  do {                                                                         \
    if (DBG_PRINT_ENABLE)                                                      \
      printf("<%s:%d> %s", __FILE__, __LINE__, __VA_ARGS__);                   \
  } while (0)

// TODO unused vars should only happen when the consistency of the API forces
//      an unnecessary object to be passed, and even then should be scrutinized.
//      Check to make sure these are necessary.
#ifdef KNOCKOUT_UNUSED
#define UNUSED(x) (void)(x)
#else
#define UNUSED(x)                                                              \
  do {                                                                         \
  } while (0)
#endif

// NB.  This allocates the objects, not just an array of pointers.
#define pprofgrow(x, N, M)                                                     \
  __extension__({                                                              \
    int __rc = 0;                                                              \
    if (!(N) % (M)) {                                                          \
      DBG_PRINT("Resizing " #x);                                               \
      __typeof__(x) _buf = calloc((N) + (M), sizeof(__typeof__(x)));           \
      if (!_buf)                                                               \
        __rc = -1;                                                             \
      if (x) {                                                                 \
        memcpy(_buf, (x), (N) * sizeof(__typeof__(x)));                        \
        free(x);                                                               \
      }                                                                        \
      (x) = _buf;                                                              \
    }                                                                          \
    __rc;                                                                      \
  })

/******************************************************************************\
|*                            String Table (Vocab)                            *|
\******************************************************************************/
// NB this is a string table implementation with linear-time insertion.  It's
//    here as a horrible baseline.
#define VOCAB_SZ 4096

size_t addToVocab(const char *str, char ***_st, size_t *_sz_st) {
  if (!str)
    str = "";
  char **st = *_st;
  size_t sz_st = *_sz_st;

  // Does this string already exist in the table?
  for (size_t i = 0; i < sz_st; i++)
    if (!strcmp(st[i], str))
      return i;

  // We have a new string.  Resize the string table if needed.
  if (!(sz_st % VOCAB_SZ)) {
    DBG_PRINT("Resizing vocab");
    char **buf = calloc(sz_st + VOCAB_SZ, sizeof(char *));
    if (!buf) {
      return (~0); // Error!
    }
    memcpy(buf, st, sz_st * sizeof(char *));
    free(*_st);
    st = *_st = buf;
  }

  st[sz_st] = strdup(str);
  (*_sz_st)++;
  return sz_st;
}

uint64_t vocab_intern(void *state, const char *str) {
  PPProfile *pprof = (PPProfile *)state;
  return addToVocab(str, &pprof->string_table, &pprof->n_string_table);
}

char **vocab_get_table(void *state) {
  PPProfile *pprof = (PPProfile *)state;
  return pprof->string_table;
}

size_t vocab_get_size(void *state) {
  PPProfile *pprof = (PPProfile *)state;
  return pprof->n_string_table;
}

/******************************************************************************\
|*                       String Table (string_table.h)                        *|
\******************************************************************************/
#define STR_LEN_PTR(x) ((uint32_t *)&(x)[-4])
#define STR_LEN(x) (*STR_LEN_PTR(x))
uint64_t pprof_stringtable_intern(void *state, const char *str) {
  return stringtable_add_cstr((StringTable *)state, str);
}

char **pprof_stringtable_gettable(void *state) {
  return (char **)((StringTable *)state)->arena->entry;
}

unsigned char *pprof_stringtable_get(void *state, uint64_t idx, uint64_t *sz) {
  unsigned char *ret = stringtable_get((StringTable *)state, idx);
  if (!ret)
    return NULL;

  if (sz)
    *sz = STR_LEN(ret);
  return ret;
}

size_t pprof_stringtable_size(void *state) {
  return ((StringTable *)state)->arena->entry_idx;
}

size_t pprof_strIntern(DProf *dp, const char *str) {
  return dp->intern_string(dp->string_table_data, str);
}

/******************************************************************************\
|*                                   DProf                                    *|
\******************************************************************************/
// Forward decl of purely internal functions
static uint64_t _pprof_mapNew(DProf *, uint64_t, uint64_t, uint64_t, uint64_t,
                              int64_t);
static uint64_t _pprof_funNew(DProf *, int64_t, int64_t, int64_t, int64_t);
static uint64_t _pprof_locNew(DProf *, uint64_t, uint64_t, const uint64_t *,
                              const int64_t *, size_t);
static uint64_t _pprof_lineNew(DProf *, PPLocation *, uint64_t, int64_t);
static char _pprof_mapFree(PPMapping **, size_t);

// ---- Implementation
static uint64_t _pprof_mapNew(DProf *dp, uint64_t map_start, uint64_t map_end,
                              uint64_t map_off, uint64_t id_filename,
                              int64_t build) {
  PPProfile *pprof = &dp->pprof;
  uint64_t id = pprof->n_mapping;

  // Resize if needed
  pprofgrow(pprof->mapping, pprof->n_mapping, DPROF_CHUNK_SZ);

  // Initialize this mapping
  pprof->mapping[id] = calloc(1, sizeof(PPMapping));
  if (!pprof->mapping[id]) {} // TODO error
  perftools__profiles__mapping__init(pprof->mapping[id]);

  // Populate specific mapping
  pprof->mapping[id]->id = id + 1;
  pprof->mapping[id]->memory_start = map_start;
  pprof->mapping[id]->memory_limit = map_end;
  pprof->mapping[id]->file_offset = map_off;
  pprof->mapping[id]->filename = id_filename;
  pprof->mapping[id]->build_id = build;

  // TODO, distinguish between these
  pprof->mapping[id]->has_filenames = 1;
  pprof->mapping[id]->has_functions = 1;

  // Done!
  pprof->n_mapping++;
  return id;
}

char isEqualMapping(uint64_t map_start, uint64_t map_end, uint64_t map_off,
                    int64_t id_filename, int64_t build, PPMapping *B) {
  return id_filename == B->filename && build == B->build_id &&
      map_end == B->memory_limit && map_start == B->memory_start &&
      map_off == B->file_offset;
}

// Returns the ID of the mapping
// NOTE that this is different from the index of the mapping in the pprof
uint64_t pprof_mapAdd(DProf *dp, uint64_t map_start, uint64_t map_end,
                      uint64_t map_off, const char *filename,
                      const char *build) {
  PPProfile *pprof = &dp->pprof;
  uint64_t id_filename = pprof_strIntern(dp, filename);
  uint64_t id_build = pprof_strIntern(dp, build);

  for (size_t i = 0; i < pprof->n_mapping; i++)
    if (isEqualMapping(map_start, map_end, map_off, id_filename, id_build,
                       pprof->mapping[i]))
      return i + 1;
  return _pprof_mapNew(dp, map_start, map_end, map_off, id_filename, id_build) +
      1;
}

static uint64_t _pprof_lineNew(DProf *dp, PPLocation *loc, uint64_t id_function,
                               int64_t line) {
  UNUSED(dp);
  uint64_t id = loc->n_line;

  // Initialize
  pprofgrow(loc->line, loc->n_line, DPROF_CHUNK_SZ);

  // Take care of this element
  loc->line[id] = calloc(1, sizeof(PPLine));
  if (!loc->line[id]) {} // TODO error
  perftools__profiles__line__init(loc->line[id]);

  // Populate this entry
  loc->line[id]->line = line;
  loc->line[id]->function_id = id_function;

  // Done!
  loc->n_line++;
  return id;
}

// Returns the ID of the line
// NOTE that this is different from the index of the line in the location
uint64_t pprof_lineAdd(DProf *dp, PPLocation *loc, uint64_t id_function,
                       int64_t line) {
  for (size_t i = 0; i < loc->n_line; i++)
    if (id_function == loc->line[i]->function_id && line == loc->line[i]->line)
      return i + 1;

  return _pprof_lineNew(dp, loc, id_function, line) + 1;
}

char isEqualFunction(int64_t id_name, int64_t id_system_name,
                     int64_t id_filename, PPFunction *B) {
  return id_name == B->name && id_system_name == B->system_name &&
      id_filename == B->filename;
}

static uint64_t _pprof_funNew(DProf *dp, int64_t id_name,
                              int64_t id_system_name, int64_t id_filename,
                              int64_t start_line) {
  PPProfile *pprof = &dp->pprof;
  uint64_t id = pprof->n_function;

  // Initialize or grow if needed
  pprofgrow(pprof->function, pprof->n_function, DPROF_CHUNK_SZ);

  // Initialize this function
  pprof->function[id] = calloc(1, sizeof(PPFunction));
  if (!pprof->function[id]) {} // TODO error
  perftools__profiles__function__init(pprof->function[id]);

  // Populate a new function
  pprof->function[id]->id = id + 1;
  pprof->function[id]->name = id_name;
  pprof->function[id]->system_name = id_system_name;
  pprof->function[id]->filename = id_filename;
  pprof->function[id]->start_line = start_line;

  // Done!
  pprof->n_function++;
  return id;
}
#include <ctype.h>

uint64_t pprof_funAdd(DProf *dp, const char *name, const char *system_name,
                      const char *filename, int64_t start_line) {
  PPProfile *pprof = &dp->pprof;
  if (!name)
    name = "??";
  for (unsigned long i = 0; i < strlen(name); i++)
    if (!isgraph(name[i])) {
      name = "??";
      break;
    }
  if (!system_name)
    system_name = "??";
  for (unsigned long i = 0; i < strlen(system_name); i++)
    if (!isgraph(system_name[i])) {
      system_name = "??";
      break;
    }
  if (!filename)
    filename = "??";
  for (unsigned long i = 0; i < strlen(filename); i++)
    if (!isgraph(filename[i])) {
      filename = "??";
      break;
    }

  int64_t id_name = pprof_strIntern(dp, name);
  int64_t id_system_name = pprof_strIntern(dp, system_name);
  int64_t id_filename = pprof_strIntern(dp, filename);

  for (size_t i = 0; i < pprof->n_function; i++)
    if (isEqualFunction(id_name, id_system_name, id_filename,
                        pprof->function[i]))
      return i + 1;
  return _pprof_funNew(dp, id_name, id_system_name, id_filename, start_line) +
      1;
}

static uint64_t _pprof_locNew(DProf *dp, uint64_t id_mapping, uint64_t addr,
                              const uint64_t *functions, const int64_t *lines,
                              size_t n_functions) {
  PPProfile *pprof = &dp->pprof;
  uint64_t id = pprof->n_location;

  // Early sanity checks
  if (!n_functions)
    return 0;
  if (!functions)
    return 0;
  if (!lines)
    return 0;

  // Initialize or grow if needed
  pprofgrow(pprof->location, pprof->n_location, DPROF_CHUNK_SZ);

  // Initialize this location
  pprof->location[id] = calloc(1, sizeof(PPLocation));
  if (!pprof->location[id]) {} // TODO error
  perftools__profiles__location__init(pprof->location[id]);

  // Populate
  pprof->location[id]->id = id + 1;
  pprof->location[id]->mapping_id = id_mapping;
  pprof->location[id]->address = addr;

  for (size_t i = 0; i < n_functions; i++)
    pprof_lineAdd(dp, pprof->location[id], functions[i], lines[i]);

  // Done!
  pprof->n_location++;
  return id;
}

static bool isEqualLine(const PPLine *line, uint64_t function_id,
                        int64_t lineno) {
  uint64_t fid_a = line->function_id, fid_b = function_id;
  int64_t line_a = line->line, line_b = lineno;
  return !((fid_a ^ fid_b) | (line_a ^ line_b));
}

static bool isEqualLocation(const PPLocation *location, uint64_t mapping_id,
                            uint64_t addr, const uint64_t *functions,
                            const int64_t *lines, size_t n_functions) {
  if (location->mapping_id != mapping_id)
    return false;
  if (location->address != addr)
    return false;
  if (location->n_line != n_functions)
    return false;

  for (size_t i = 0; i < n_functions; ++i) {
    if (!isEqualLine(location->line[i], functions[i], lines[i])) {
      return false;
    }
  }

  return true;
}

static PPLocation *findLocation(DProf *dp, uint64_t id_mapping, uint64_t addr,
                                const uint64_t *functions, const int64_t *lines,
                                size_t n_functions) {
  PPProfile *pprof = &dp->pprof;
  for (size_t i = 0; i < pprof->n_location; ++i) {
    PPLocation *location = pprof->location[i];
    if (isEqualLocation(location, id_mapping, addr, functions, lines,
                        n_functions))
      return location;
  }
  return NULL;
}

uint64_t pprof_locAdd(DProf *dp, uint64_t id_mapping, uint64_t addr,
                      const uint64_t *functions, const int64_t *lines,
                      size_t n_functions) {
  PPLocation *location =
      findLocation(dp, id_mapping, addr, functions, lines, n_functions);
  return (location)
      ? location->id
      : _pprof_locNew(dp, id_mapping, addr, functions, lines, n_functions) + 1;
}

static bool isEqualSample(const PPSample *B, const uint64_t *loc, size_t nloc) {
  if (nloc != B->n_location_id)
    return false;
  for (size_t i = 0; i != nloc; i++) {
    if (loc[i] != B->location_id[i])
      return false;
  }
  return true;
}

static PPSample *findSample(DProf *dp, uint64_t *loc, size_t nloc) {
  PPProfile *pprof = &dp->pprof;
  for (size_t i = 0; i != pprof->n_sample; ++i) {
    PPSample *sample = pprof->sample[i];
    if (isEqualSample(sample, loc, nloc)) {
      return sample;
    }
  }
  return NULL;
}

char pprof_sampleAdd(DProf *dp, int64_t *val, size_t nval, uint64_t *loc,
                     size_t nloc) {
  PPProfile *pprof = &dp->pprof;
  uint64_t id = pprof->n_sample;

  // Early sanity checks
  if (nval != pprof->n_sample_type)
    return -1; // pprof and user disagree
  if (!val)
    return -1; // samples are null
  if (!nloc || !loc)
    return -1; // no locations

  PPSample *sample = findSample(dp, loc, nloc);
  if (sample) {
    for (size_t j = 0; j != sample->n_value; ++j) {
      // todo: do different sample types need to be handled differently?
      sample->value[j] += val[j];
    }
    return 0;
  }

  // Initialize the sample, possibly expanding if needed
  pprofgrow(pprof->sample, pprof->n_sample, DPROF_CHUNK_SZ);

  // Initialize this sample
  pprof->sample[id] = calloc(1, sizeof(PPSample));
  if (!pprof->sample[id]) {} // TODO error
  perftools__profiles__sample__init(pprof->sample[id]);

  // Populate the sample value.  First, validate and allocate
  pprof->sample[id]->n_value = nval;
  pprof->sample[id]->value = calloc(nval, sizeof(int64_t));
  if (!pprof->sample[id]->value) {} // TODO error
  memcpy(pprof->sample[id]->value, val, nval * sizeof(*val));

  // Populate the location IDs
  pprof->sample[id]->n_location_id = nloc;
  pprof->sample[id]->location_id = calloc(nloc, sizeof(uint64_t));
  if (!pprof->sample[id]->location_id) {} // TODO error
  memcpy(pprof->sample[id]->location_id, loc, nloc * sizeof(*loc));

  // We're done!
  pprof->n_sample++;
  return 0;
}

char pprof_sampleFree(PPSample **sample, size_t sz) {
  if (!sample) // TODO is this an error?
    return 0;

  for (size_t i = 0; i < sz; i++) {
    if (!sample[i])
      continue;

    if (sample[i]->location_id) {
      free(sample[i]->location_id);
      sample[i]->location_id = NULL;
    }

    if (sample[i]->value) {
      free(sample[i]->value);
      sample[i]->value = NULL;
    }

    free(sample[i]);
    sample[i] = NULL;
  }

  free(sample);
  return 0;
}

static inline void _pprof_durationSet(DProf *dp, int64_t duration_nanos) {
  dp->pprof.duration_nanos = duration_nanos;
}

static inline void _pprof_timeSet(DProf *dp, int64_t time_nanos) {
  dp->pprof.time_nanos = time_nanos;
}

void pprof_durationSet(DProf *dp, int64_t duration_nanos) {
  _pprof_durationSet(dp, duration_nanos);
}

void pprof_timeSet(DProf *dp, int64_t time_nanos) {
  _pprof_timeSet(dp, time_nanos);
}

void pprof_timeUpdate(DProf *dp) {
  if (!dp)
    return;
  struct timeval tv = {0};
  gettimeofday(&tv, NULL);
  pprof_timeSet(dp, (tv.tv_sec * 1000 * 1000 + tv.tv_usec) * 1000);
}

void pprof_durationUpdate(DProf *dp) {
  if (!dp)
    return;
  struct timeval tv = {0};
  gettimeofday(&tv, NULL);
  int64_t duration = (tv.tv_sec * 1000 * 1000 + tv.tv_usec) * 1000 -
      dp->pprof.time_nanos + dp->pprof.duration_nanos;
  _pprof_durationSet(dp, duration);
}

DProf *pprof_Init(DProf *dp, const char **sample_names,
                  const char **sample_units, size_t n_sampletypes) {
  if (!dp) {
    dp = calloc(1, sizeof(*dp));
    if (!dp)
      return NULL;
    dp->ownership = 1;
  }

  PPProfile *pprof = &dp->pprof;
  // Early sanity checks
  if (!sample_names || !sample_units || 2 > n_sampletypes)
    goto dpi_cleanup01;

  // Define the appropriate internment strategy
  dp->table_type = 1; // aggressively!
  switch (dp->table_type) {
  case 0:
    dp->intern_string = vocab_intern;
    dp->string_table = vocab_get_table;
    dp->string_table_size = vocab_get_size;
    dp->string_table_get = NULL; // TODO, this is an error!!!
    dp->string_table_data = pprof;
    break;
  default: // Error, default to best implementation so far
  case 1:
    dp->intern_string = pprof_stringtable_intern;
    dp->string_table = pprof_stringtable_gettable;
    dp->string_table_get = pprof_stringtable_get;
    dp->string_table_size = pprof_stringtable_size;
    dp->string_table_data =
        stringtable_init(NULL, &(StringTableOptions){.hash = 1, .logging = 0});
    ((StringTable *)dp->string_table_data)->logging =
        1; // TODO make this configurable
    break;
  }

  // Initialize the top-level container and the type holders
  perftools__profiles__profile__init(pprof);
  pprof_strIntern(dp, ""); // Initialize

  // Initialize sample_type
  pprof->sample_type = calloc(n_sampletypes, sizeof(PPValueType *));
  if (!pprof->sample_type) {} // TODO error
  pprof->n_sample_type = n_sampletypes;
  pprof->n_sample = 0;

  // Populate individual sample values
  for (size_t i = 0; i < n_sampletypes; i++) {
    pprof->sample_type[i] = calloc(1, sizeof(PPValueType));
    if (!pprof->sample_type[i]) {} // TODO error
    perftools__profiles__value_type__init(pprof->sample_type[i]);

    pprof->sample_type[i]->type = pprof_strIntern(dp, sample_names[i]);
    pprof->sample_type[i]->unit = pprof_strIntern(dp, sample_units[i]);
  }

  // Initialize period_type
  pprof->period_type = calloc(1, sizeof(PPValueType));
  if (!pprof->period_type) {} // TODO error
  perftools__profiles__value_type__init(pprof->period_type);
  pprof->period_type->type = pprof->sample_type[1]->type;
  pprof->period_type->unit = pprof->sample_type[1]->unit;

  return dp;

dpi_cleanup01:
  if (dp->ownership)
    free(dp);
  return NULL;
}

static char _pprof_mapFree(PPMapping **map, size_t sz) {
  if (!map) // TODO err?
    return 0;

  // TODO when we change all mappings to get allocated into a common arena,
  // update this
  for (size_t i = 0; i < sz; i++)
    if (map[i]) {
      free(map[i]);
      map[i] = NULL;
    }

  free(map);
  map = NULL;
  return 0;
}

char pprof_lineFree(PPLine **line, size_t sz) {
  if (!line)
    return 0;

  // TODO refactor when ready
  for (size_t i = 0; i < sz; i++)
    if (line[i]) {
      free(line[i]);
      line[i] = NULL;
    }

  free(line);
  line = NULL;
  return 0;
}

char pprof_locFree(PPLocation **loc, size_t sz) {
  if (!loc)
    return 0;

  // TODO refactor when ready
  for (size_t i = 0; i < sz; i++)
    if (loc[i]) {
      pprof_lineFree(loc[i]->line, loc[i]->n_line);
      loc[i]->line = NULL;
      free(loc[i]);
      loc[i] = NULL;
    }

  free(loc);
  loc = NULL;
  return 0;
}

char pprof_funFree(PPFunction **fun, size_t sz) {
  if (!fun)
    return 0;

  // TODO refactor when ready
  for (size_t i = 0; i < sz; i++)
    if (fun[i]) {
      free(fun[i]);
      fun[i] = NULL;
    }

  free(fun);
  fun = NULL;
  return 0;
}

char pprof_sampleClear(DProf *dp) {
  PPProfile *pprof = &dp->pprof;
  if (!pprof)
    return 0;

  if (pprof->sample)
    pprof_sampleFree(pprof->sample, pprof->n_sample);
  pprof->sample = NULL;
  pprof->n_sample = 0;

  return 0;
}

void pprof_Free(DProf *dp) {
  if (!dp)
    return;
  PPProfile *pprof = &dp->pprof;

  if (pprof->sample_type) {
    for (size_t i = 0; i < pprof->n_sample_type; i++)
      if (pprof->sample_type[i]) {
        free(pprof->sample_type[i]);
        pprof->sample_type[i] = NULL;
      }
    free(pprof->sample_type);
    pprof->sample_type = NULL;
  }

  if (pprof->period_type) {
    free(pprof->period_type);
    pprof->period_type = NULL;
  }

  if (pprof->mapping) {
    _pprof_mapFree(pprof->mapping, pprof->n_mapping);
    pprof->mapping = NULL;
  }

  if (pprof->location) {
    pprof_locFree(pprof->location, pprof->n_location);
    pprof->location = NULL;
  }

  if (pprof->function) {
    pprof_funFree(pprof->function, pprof->n_function);
    pprof->function = NULL;
  }

  if (pprof->sample) {
    pprof_sampleFree(pprof->sample, pprof->n_sample);
    pprof->sample = NULL;
  }

  switch (dp->table_type) {
  case 0:
    if (pprof->string_table) {
      for (size_t i = 0; i < pprof->n_string_table; i++)
        if (pprof->string_table[i]) {
          free(pprof->string_table[i]);
          pprof->string_table[i] = NULL;
        }
      free(pprof->string_table);
      pprof->string_table = NULL;
    }
    break;
  default:
  case 1:
    stringtable_free(dp->string_table_data);
    break;
  }

  if (pprof->comment) {
    free(pprof->comment);
    pprof->comment = NULL;
  }
}

unsigned char *pprof_getstr(DProf *dp, uint64_t idx, uint64_t *sz) {
  uint64_t _sz;
  unsigned char *ret = dp->string_table_get(dp->string_table_data, idx, &_sz);
  if (!ret)
    return NULL;
  if (sz)
    *sz = _sz;
  return ret;
}

inline static void pretty_print(char c) {
  printf(isgraph(c) || isspace(c) ? "%c" : "//%o", c);
}
void pprof_print(DProf *dp) {
  PPProfile *pprof = &dp->pprof;
  size_t SZ = dp->string_table_size(dp->string_table_data);

  // Print ValueType

  // print samples
  for (size_t i = 0; i < pprof->n_sample; i++) {
    printf("smplID: %ld\n", i);
    printf("  nloc: %zu\n", pprof->sample[i]->n_location_id);
    for (size_t j = 0; j < pprof->sample[i]->n_location_id; j++) {
      printf("  locID: %ld\n", pprof->sample[i]->location_id[j]);
    }
  }
  // print mapping
  // Print locations
  for (size_t i = 0; i < pprof->n_location; i++) {
    printf("locID: %ld\n", i);
    printf("  MapID: %ld\n", pprof->location[i]->mapping_id);
    printf("  Addr: %lx\n", pprof->location[i]->address);
    for (size_t j = 0; j < pprof->location[i]->n_line; j++) {
      printf("    LineID: %ld\n", j);
      printf("    FunID: %ld\n", pprof->location[i]->line[j]->function_id);
    }
  }
  // print line
  // print functions

  // print strings
  for (size_t i = 0; i < SZ; i++) {
    unsigned char *str = pprof_getstr(dp, i, NULL);
    size_t sz = STR_LEN(str);
    printf("Str %ld(%lu): ", i, sz);
    for (size_t j = 0; j < sz; j++)
      pretty_print(str[i]);
    printf("\n");
  }
}

/******************************************************************************\
|*                        Compression Helper Functions                        *|
\******************************************************************************/
void GZip(char *file, const char *data, const size_t sz_data) {
  gzFile fi = gzopen(file, "wb9");
  gzwrite(fi, data, sz_data);
  gzclose(fi);
  struct stat st = {0};
  stat(file, &st);
}

size_t pprof_zip(DProf *dp, unsigned char *ret, const size_t sz_packed) {
  PPProfile *pprof = &dp->pprof;
  // Assumes the ret buffer has already been sized to at least sz_packed
  // Serialized pprof
  void *packed = malloc(sz_packed); // TODO check for err?
  perftools__profiles__profile__pack(pprof, packed);
  memset(ret, 0, sz_packed);

  // Compress
  z_stream zs = {.avail_in = sz_packed,
                 .avail_out = sz_packed,
                 .next_in = packed,
                 .next_out = ret};
  deflateInit2(&zs, Z_BEST_COMPRESSION, Z_DEFLATED, 15 + 16, 8,
               Z_DEFAULT_STRATEGY);
  deflate(&zs, Z_FINISH);
  deflateEnd(&zs);
  free(packed);

  return zs.total_out;
}

void pprof_finalize(DProf *dp) {
  // Update the string table parameters and anything else that isn't auto-
  // matically up-to-spec with pprof
  dp->pprof.string_table = dp->string_table(dp->string_table_data);
  dp->pprof.n_string_table = dp->string_table_size(dp->string_table_data);

  // Update pprof timing details
  pprof_durationUpdate(dp);
}

unsigned char *pprof_flush(DProf *dp, size_t *sz) {
  // Finalize
  pprof_finalize(dp);

  // Serialize and zip pprof
  unsigned char *buf;
  size_t sz_packed = perftools__profiles__profile__get_packed_size(&dp->pprof);
  size_t sz_zipped = pprof_zip(dp, (buf = malloc(sz_packed)), sz_packed);

#ifdef DD_DBG_PROFGEN
  // Optionally for debug purposes, emit a pprof to disk
  mkdir("./pprofs", 0777);
  unlink("./pprofs/test.pb.gz");
  int fd = open("./pprofs/test.pb.gz", O_RDWR | O_CREAT, 0677);
  write(fd, buf, sz_zipped);
  close(fd);

  unsigned char *thisbuf = malloc(sz_packed);
  size_t thislen = perftools__profiles__profile__pack(&dp->pprof, thisbuf);
  GZip("./pprofs/test.pb", (const char *)thisbuf, thislen);
  free(thisbuf);
#endif

  // Reset the pprof according to the clearing semantics set in the dp
  switch (dp->flushmode) {
  case 0:
  case 1:
  case 2:
  case 3:
  default:
    pprof_sampleClear(dp);
    break;
  }

  // Wrap up.  It's up to the caller to free.
  if (sz)
    *sz = sz_zipped;
  return buf;
}

#undef STR_LEN_PTR
#undef STR_LEN
