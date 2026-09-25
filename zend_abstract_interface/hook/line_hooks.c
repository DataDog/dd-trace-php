#include "line_hooks.h"

#include "hook.h"
#include "table.h"

#include <php.h>
#include <zend_generators.h>

#include <components/log/log.h>
#include <interceptor/line_hook.h>

/* Line-hook IDs are negative so a consumer can share one id space with zai_hook_install(), whose IDs are positive; zero stays the install failure sentinel. */

typedef struct {
    zend_long id;
    zend_string *file;
    uint32_t line;
    uint32_t end_line;
    zai_line_hooks_begin begin;
    zai_line_hooks_end end;
    zai_line_hooks_frame_event suspend; /* NULL unless installed through the generator entry point */
    zai_line_hooks_frame_event resume;
    zai_line_hooks_aux aux;
    size_t dynamic;      /* payload bytes handed to each invocation */
    bool eager_range;    /* open a range at every begin, rather than on request */
    bool removed;        /* removal was requested; no further begin fires, but open ranges still close */
    uint32_t open_count; /* frames with this range currently open, across the whole request */
    HashTable guards;    /* zai_line_guard* -> itself: the guards this def is keeping alive */
    /* Sites armed by this definition: mixed opline key -> raw opline.
       Keys remain safe to look up after op_array destruction removes a site. */
    HashTable sites;
} zai_line_def;

/* One guard per (opcodes, scope), shared by definitions.
   Remove its hook when the last definition releases it. */
typedef struct {
    zend_long id;
    zai_install_address address; /* what zai_hook_remove_resolved() needs */
    zend_ulong opcodes_key;      /* where in zai_line_guards this record lives, so releasing it is a delete */
    zend_ulong scope_key;
    /* defs controls the installed hook; refs controls the allocation, held by the guard set and each definition.
       Definitions may retain the record after op_array destruction removes the set entry. */
    uint32_t defs;
    uint32_t refs;
    bool retired; /* the op_array is gone, so the hook and the set entry went with it */
} zai_line_guard;

/* One armed opline can be the begin site of one range and the end site of another, so a site records both roles. */
typedef struct {
    zai_line_def *def;
    bool is_begin;
    bool is_end;
    bool has_range;
} zai_line_site_entry;

/* One record per frame holding open ranges.
   The guard stores a pointer to it: joining a hook may reallocate its dynamic record while a callback still uses the state. */
typedef struct {
    HashTable open; /* def id -> invocation payload */
} zai_line_frame;

/* Request-scoped records prevent stale opcode pointers across OPcache restarts. */
ZEND_TLS HashTable zai_line_defs;  /* id -> zai_line_def*       */
ZEND_TLS HashTable zai_line_armed; /* opline key -> zai_line_site* */
ZEND_TLS zend_long zai_line_next_id;
ZEND_TLS bool zai_line_initialized;

/* Callbacks may install or remove hooks during dispatch.
   Queue drops until dispatch finishes using the definitions and sites. */
ZEND_TLS uint32_t zai_line_dispatch_depth;
ZEND_TLS HashTable zai_line_pending_drop; /* id -> (void *)1 */

/* A begin callback can open its own range through this context. */
struct zai_line_invocation {
    zai_line_def *def;
    zend_execute_data *frame;
    const zend_op *opline;
    void *dynamic;
    zai_line_frame *state;
    bool opened;
};

static void zai_line_def_free(zval *zv) {
    zai_line_def *def = Z_PTR_P(zv);
    zend_hash_destroy(&def->guards);
    zend_hash_destroy(&def->sites);
    if (def->file) {
        zend_string_release(def->file);
    }
    if (def->aux.dtor) {
        def->aux.dtor(def->aux.data);
    }
    efree(def);
}

/* A site is the set of roles armed on one opline, keyed by def id so removal is a hash delete, not a list walk. */
static void zai_line_site_entry_free(zval *zv) { efree(Z_PTR_P(zv)); }

/* An end-only guard closes open ranges on frame exit.
   Key by opcodes to match Closure op_array copies, then by scope for trait methods shared by distinct classes. */
ZEND_TLS HashTable zai_line_guards; /* op_array->opcodes -> HashTable(op_array->scope -> zai_line_guard*) */

#if PHP_VERSION_ID >= 70400
/* filename -> zend_llist of preloaded op_arrays.
   Build once per request; the flag distinguishes an empty index from an unbuilt one. */
ZEND_TLS HashTable zai_line_preloaded;
ZEND_TLS bool zai_line_preloaded_built;
#endif

/* Release a definition's allocation reference, including after RSHUTDOWN has destroyed the guard sets. */
static void zai_line_guard_claim_free(zval *zv) {
    zai_line_guard *guard = Z_PTR_P(zv);
    if (!--guard->refs) {
        efree(guard);
    }
}

/* Release the guard set's reference; definitions may still hold theirs. */
static void zai_line_guard_free(zval *zv) {
    zai_line_guard *guard = Z_PTR_P(zv);
    guard->retired = true;
    if (!--guard->refs) {
        efree(guard);
    }
}

/* Release this definition's guards. zai_hook defers removal while their frames are still running. */
static void zai_line_release_guards(zai_line_def *def) {
    zai_line_guard *guard;
    ZEND_HASH_FOREACH_PTR(&def->guards, guard) {
        if (!--guard->defs && !guard->retired) {
            /* Remove the last definition's live guard. Retired guards already lost their hook and set entry. */
            zai_hook_remove_resolved(guard->address, guard->id);

            zend_ulong opcodes_key = guard->opcodes_key;
            HashTable *set = zend_hash_index_find_ptr(&zai_line_guards, opcodes_key);
            if (set) {
                /* Drops the set's claim; this def still holds one, so the record cannot be freed underneath us. */
                zend_hash_index_del(set, guard->scope_key);
                if (!zend_hash_num_elements(set)) {
                    zend_hash_index_del(&zai_line_guards, opcodes_key);
                }
            }
        }
    }
    ZEND_HASH_FOREACH_END();
    zend_hash_clean(&def->guards); /* zai_line_guard_claim_free() drops each allocation claim */
}

/* Dtor for a HashTable held by value in another HashTable. */
static void zai_line_nested_ht_free(zval *zv) {
    HashTable *ht = Z_PTR_P(zv);
    zend_hash_destroy(ht);
    efree(ht);
}

typedef struct {
    const zend_op *opline; /* Raw address; the mixed table key cannot be cast back to an opline. */
    HashTable defs;        /* def id -> zai_line_site_entry* */
} zai_line_site;

static void zai_line_site_free(zval *zv) {
    zai_line_site *site = Z_PTR_P(zv);
    zend_hash_destroy(&site->defs);
    efree(site);
}

/* Unarms every site the def holds and frees it. Never called with dispatch on the stack -- see zai_line_def_drop. */
static void zai_line_def_drop_now(zend_long id) {
    zai_line_def *def = zend_hash_index_find_ptr(&zai_line_defs, (zend_ulong)id);

    if (def) {
        /* Iteration uses def->sites; deletion uses zai_line_armed.
           Site cleanup and disarming invoke no user callbacks, so neither can mutate the iterated table. */
        zend_ulong site_key;
        void *site_opline;
        ZEND_HASH_FOREACH_NUM_KEY_PTR(&def->sites, site_key, site_opline) {
            zai_line_site *site = zend_hash_index_find_ptr(&zai_line_armed, site_key);
            if (!site) {
                continue; /* the op_array was destroyed and took the site with it */
            }
            zend_hash_index_del(&site->defs, (zend_ulong)id);
            if (!zend_hash_num_elements(&site->defs)) {
                zai_line_hook_disarm((zend_op *)site_opline);
                zend_hash_index_del(&zai_line_armed, site_key);
            }
        }
        ZEND_HASH_FOREACH_END();

        zai_line_release_guards(def);
    }
    zend_hash_index_del(&zai_line_defs, (zend_ulong)id);
}

static void zai_line_def_drop(zend_long id) {
    if (zai_line_dispatch_depth) {
        zend_hash_index_update_ptr(&zai_line_pending_drop, (zend_ulong)id, (void *)1);
        return;
    }
    zai_line_def_drop_now(id);
}

static void zai_line_drain_pending(void) {
    /* Detach each batch so destructors can queue further removals.
       Negative hook IDs keep the table unpacked. */
    while (zend_hash_num_elements(&zai_line_pending_drop)) {
        Bucket *batch = zai_line_pending_drop.arData;
        uint32_t count = zai_line_pending_drop.nNumUsed;
        void *data = HT_GET_DATA_ADDR(&zai_line_pending_drop);
        zend_hash_init(&zai_line_pending_drop, 8, NULL, NULL, 0);

        for (uint32_t i = 0; i < count; ++i) {
            if (!Z_ISUNDEF(batch[i].val)) {
                zai_line_def_drop_now((zend_long)batch[i].h);
            }
        }
        efree(data);
    }
}

/* Called once a range instance has closed: a def whose removal was deferred goes away with its last open range. */
static void zai_line_range_closed(zai_line_def *def) {
    if (def->open_count) {
        --def->open_count;
    }
    if (def->removed && !def->open_count) {
        zai_line_def_drop(def->id);
    }
}

/* Join the frame's own scope guard when available, otherwise any guard over these opcodes.
   Finish uses the recorded hook without filtering scope; zai_line_frame_state() finds that record. */
static bool zai_line_frame_guard_id(zend_execute_data *frame, zend_long *out) {
    zend_op_array *op_array = &frame->func->op_array;
    HashTable *set = zend_hash_index_find_ptr(&zai_line_guards, (zend_ulong)(uintptr_t)op_array->opcodes);
    if (!set) {
        return false;
    }

    zai_line_guard *guard = zend_hash_index_find_ptr(set, (zend_ulong)(uintptr_t)op_array->scope);
    if (!guard) {
        zai_line_guard *any;
        ZEND_HASH_FOREACH_PTR(set, any) {
            guard = any;
            break;
        }
        ZEND_HASH_FOREACH_END();
    }
    if (!guard) {
        return false;
    }

    *out = guard->id;
    return true;
}

static zai_line_frame *zai_line_frame_state(zend_execute_data *frame, bool create) {
    zend_op_array *op_array = &frame->func->op_array;
    HashTable *set = zend_hash_index_find_ptr(&zai_line_guards, (zend_ulong)(uintptr_t)op_array->opcodes);
    if (!set) {
        return NULL;
    }

    /* Use the first guard with a frame record; scope-filtered or newly installed guards have none.
       Stable insertion order keeps begin and end on the same state. */
    zai_line_frame **slot = NULL;
    zai_line_guard *guard;
    ZEND_HASH_FOREACH_PTR(set, guard) {
        slot = zai_hook_frame_dynamic(frame, guard->id);
        if (slot) {
            break;
        }
    }
    ZEND_HASH_FOREACH_END();

    if (!slot) {
        return NULL;
    }
    if (!*slot && create) {
        *slot = ecalloc(1, sizeof(**slot));
        zend_hash_init(&(*slot)->open, 2, NULL, NULL, 0);
    }
    return *slot;
}

static zai_line_frame *zai_line_join_frame(zend_execute_data *frame) {
    zai_line_frame *state = zai_line_frame_state(frame, true);
    if (!state) {
        zend_long guard_id;
        if (zai_line_frame_guard_id(frame, &guard_id) && zai_hook_join_running_frame(frame, guard_id, true)) {
            state = zai_line_frame_state(frame, true);
        }
    }
    return state;
}

static bool zai_line_arm_range(zai_line_def *def, zend_op_array *op_array, zend_op *opline, uint32_t resolved);

/* Hand an invocation to the consumer's end, then drop it.
   Looks the definition up again afterwards: the callback may remove it, and that removal can be the one its last open range was holding off. */
static void zai_line_close(zend_execute_data *frame, zai_line_frame *state, zend_long id, uint32_t line) {
    void *dynamic = zend_hash_index_find_ptr(&state->open, (zend_ulong)id);
    if (!dynamic) {
        return;
    }
    zend_hash_index_del(&state->open, (zend_ulong)id);

    zai_line_def *def = zend_hash_index_find_ptr(&zai_line_defs, (zend_ulong)id);
    if (def && def->end) {
        /* line 0 means no opline reached this -- the frame is unwinding -- so the range's own end line is the best answer available. */
        def->end(frame, line ? line : def->end_line, def->aux.data, dynamic);
    }
    efree(dynamic);

    def = zend_hash_index_find_ptr(&zai_line_defs, (zend_ulong)id);
    if (def) {
        zai_line_range_closed(def);
    }
}

/* Snapshot role IDs and re-resolve definitions after callbacks, which may mutate the registry. */
typedef struct {
    zend_long id;
    uint32_t end_line;  /* how far the range reaches, which is what orders simultaneous openings */
    bool is_begin;
    bool is_end;
    bool opens_range;
} zai_line_site_role;

/* Close open ranges in reverse opening order.
   Snapshot IDs before callbacks can mutate them.
   roles selects an end site, or NULL for all ranges; line 0 denotes frame unwind. */
static void zai_line_close_open(zend_execute_data *frame, zai_line_frame *state, uint32_t line, const zai_line_site_role *roles, uint32_t count) {
    uint32_t open = zend_hash_num_elements(&state->open);
    if (!open) {
        return;
    }

    ALLOCA_FLAG(use_heap)
    zend_ulong *ids = do_alloca(open * sizeof(*ids), use_heap);
    uint32_t n = 0;
    /* The BUCKET form rather than REVERSE_FOREACH_NUM_KEY, which only exists from PHP 8. */
    Bucket *bucket;
    ZEND_HASH_REVERSE_FOREACH_BUCKET(&state->open, bucket) {
        zend_ulong id = bucket->h;
        bool wanted = !roles;
        for (uint32_t i = 0; !wanted && i < count; ++i) {
            wanted = roles[i].is_end && (zend_ulong)roles[i].id == id;
        }
        if (wanted) {
            ids[n++] = id;
        }
    }
    ZEND_HASH_FOREACH_END();

    for (uint32_t i = 0; i < n; ++i) {
        zai_line_close(frame, state, (zend_long)ids[i], line);
    }
    free_alloca(ids, use_heap);
}

/* Deliver a generator event to every range open in the frame.
   Snapshot the ids first: the walk must survive a callback that closes a range, even though callbacks are documented not to run PHP. */
static void zai_line_notify(bool resume, zend_execute_data *frame, zai_line_frame *state) {
    if (!state) {
        return;
    }
    uint32_t open = zend_hash_num_elements(&state->open);
    if (!open) {
        return;
    }

    ALLOCA_FLAG(use_heap)
    zend_ulong *ids = do_alloca(open * sizeof(*ids), use_heap);
    uint32_t n = 0;
    zend_ulong id;
    ZEND_HASH_FOREACH_NUM_KEY(&state->open, id) {
        ids[n++] = id;
    }
    ZEND_HASH_FOREACH_END();

    for (uint32_t i = 0; i < n; ++i) {
        zai_line_def *def = zend_hash_index_find_ptr(&zai_line_defs, ids[i]);
        zai_line_hooks_frame_event cb = def ? (resume ? def->resume : def->suspend) : NULL;
        if (!cb) {
            continue;
        }
        void *dynamic = zend_hash_index_find_ptr(&state->open, ids[i]);
        if (dynamic) {
            cb(frame, def->aux.data, dynamic);
        }
    }
    free_alloca(ids, use_heap);
}

void zai_line_hooks_close_frame(zend_execute_data *frame) {
    if (!zai_line_initialized || !ZEND_USER_CODE(frame->func->type)) {
        return;
    }
    zai_line_frame *state = zai_line_frame_state(frame, false);
    if (!state) {
        return;
    }
    zai_line_notify(true, frame, state);
    zai_line_close_open(frame, state, 0, NULL, 0);
}

static void zai_line_generator_resume(zend_ulong invocation, zend_execute_data *frame, zval *sent, void *auxiliary, void *dynamic) {
    (void)invocation, (void)sent, (void)auxiliary;
    zai_line_notify(true, frame, *(zai_line_frame **)dynamic);
}

static void zai_line_generator_yield(zend_ulong invocation, zend_execute_data *frame, zval *key, zval *value, void *auxiliary, void *dynamic) {
    (void)invocation, (void)key, (void)value, (void)auxiliary;
    zai_line_notify(false, frame, *(zai_line_frame **)dynamic);
}

/* Register an invocation as an open range. Shared by the eager path and zai_line_hooks_open_range(). */
static void zai_line_mark_open(zai_line_def *def, zai_line_frame *state, void *dynamic) {
    zend_hash_index_add_ptr(&state->open, (zend_ulong)def->id, dynamic);
    ++def->open_count;
    /* Removal may have been requested earlier in this same callback. */
    zend_hash_index_del(&zai_line_pending_drop, (zend_ulong)def->id);
}

bool zai_line_hooks_open_range(zai_line_invocation *cur) {
    if (!cur) {
        return false;
    }
    if (cur->opened) {
        return true;
    }

    zend_execute_data *frame = cur->frame;
    if (!zai_line_arm_range(cur->def, &frame->func->op_array, (zend_op *)cur->opline, cur->opline->lineno)) {
        return false;
    }
    zai_line_frame *state = zai_line_frame_state(frame, true);
    if (!state) {
        /* Consumer callbacks may use a separate observer chain.
           Publish its frame record now and observe the frame only after the callback returns. */
        zend_long guard_id;
        if (zai_line_frame_guard_id(frame, &guard_id) && zai_hook_join_running_frame(frame, guard_id, false)) {
            state = zai_line_frame_state(frame, true);
        }
    }
    if (!state) {
        return false;
    }

    zai_line_mark_open(cur->def, state, cur->dynamic);
    cur->state = state;
    cur->opened = true;
    return true;
}

static void zai_line_dispatch(zend_execute_data *frame, const zend_op *opline) {
    if (!zai_line_initialized) {
        return;
    }

    zai_line_site *site_rec = zend_hash_index_find_ptr(&zai_line_armed, zai_line_hook_opline_key(opline));
    HashTable *site = site_rec ? &site_rec->defs : NULL;
    uint32_t count = site ? zend_hash_num_elements(site) : 0;
    if (!count) {
        return;
    }

    ALLOCA_FLAG(use_heap)
    zai_line_site_role *roles = do_alloca(count * sizeof(*roles), use_heap);
    uint32_t n = 0;
    bool opens_range = false;
    zai_line_site_entry *entry;
    ZEND_HASH_FOREACH_PTR(site, entry) {
        roles[n].id = entry->def->id;
        roles[n].end_line = entry->def->end_line;
        roles[n].is_begin = entry->is_begin;
        roles[n].is_end = entry->is_end;
        roles[n].opens_range = entry->is_begin && entry->has_range && !entry->def->removed;
        if (roles[n].opens_range) {
            opens_range = true;
        }
        ++n;
    }
    ZEND_HASH_FOREACH_END();

    /* Begin-only callbacks can open a range too.
       Open shared-site ranges widest first, preserving registration order for equal ends. */
    ALLOCA_FLAG(slots_heap)
    uint32_t *slots = do_alloca(count * sizeof(*slots), slots_heap);
    uint32_t opening = 0;
    for (uint32_t i = 0; i < n; ++i) {
        if (roles[i].is_begin) {
            slots[opening++] = i;
        }
    }
    for (uint32_t a = 1; a < opening; ++a) {
        zai_line_site_role role = roles[slots[a]];
        uint32_t b = a;
        while (b > 0 && roles[slots[b - 1]].end_line < role.end_line) {
            roles[slots[b]] = roles[slots[b - 1]];
            --b;
        }
        roles[slots[b]] = role;
    }
    free_alloca(slots, slots_heap);

    ++zai_line_dispatch_depth;
    zai_line_frame *state = zai_line_frame_state(frame, true);
    if (!state && opens_range) {
        /* Join a frame whose guard was installed after entry when its first range opens.
           Frames already past the begin site need no record. */
        state = zai_line_join_frame(frame);
    }

    /* Deliver ends before begins at a shared opline so re-entry closes the previous instance, including repeated loop headers. */
    if (state) {
        zai_line_close_open(frame, state, opline->lineno, roles, n);
    }

    for (uint32_t i = 0; i < n; ++i) {
        if (!roles[i].is_begin) {
            continue;
        }
        zai_line_def *def = zend_hash_index_find_ptr(&zai_line_defs, (zend_ulong)roles[i].id);
        if (!def) {
            continue;
        }
        if (state && zend_hash_index_exists(&state->open, (zend_ulong)def->id)) {
            zai_line_close(frame, state, def->id, opline->lineno);
        }
        if (def->removed) {
            continue; /* deferred removal: the range may still close, but it must not open again */
        }
        if (def->eager_range && !state) {
            /* A range needs a frame record for its end.
               Skip frames that cannot be joined, including implicit yield-from leaves. */
            continue;
        }

        /* Zero-sized payloads still get an allocation, so every invocation has a distinct identity. */
        void *dynamic = ecalloc(1, def->dynamic ? def->dynamic : 1);
        zai_line_invocation invocation = {
            .def = def,
            .frame = frame,
            .opline = opline,
            .dynamic = dynamic,
            .state = state,
            .opened = false
        };

        /* Record the range before begin so self-removal, throw or bailout still leaves an owed end. */
        if (state && def->eager_range) {
            zai_line_mark_open(def, state, dynamic);
            invocation.opened = true;
        }

        bool keep = !def->begin || def->begin(&invocation, frame, opline, opline->lineno, def->aux.data, dynamic);

        if (!keep && invocation.opened) {
            /* The consumer declined the invocation: unwind the range it never used.
               A suppressed begin must not leave one open, or a generator resumed later delivers an unmatched end. */
            zend_hash_index_del(&invocation.state->open, (zend_ulong)def->id);
            invocation.opened = false;
            zai_line_def *still = zend_hash_index_find_ptr(&zai_line_defs, (zend_ulong)def->id);
            if (still) {
                zai_line_range_closed(still);
            }
        }

        if (invocation.opened) {
            if (!state) {
                zend_long guard_id;
                if (zai_line_frame_guard_id(frame, &guard_id)) {
                    zai_hook_join_running_frame(frame, guard_id, true);
                }
            }
            state = invocation.state;
        } else {
            efree(dynamic);
        }
    }

    if (!--zai_line_dispatch_depth) {
        zai_line_drain_pending();
    }
    free_alloca(roles, use_heap);
}

/* The guard closes ranges on frame exits that static exit sites cannot cover. */
static void zai_line_guard_end(zend_ulong invocation, zend_execute_data *frame, zval *retval, void *auxiliary, void *dynamic) {
    (void)invocation, (void)retval, (void)auxiliary;

    zai_line_frame **slot = dynamic;
    zai_line_frame *state = slot ? *slot : NULL;
    if (!state) {
        return;
    }
    *slot = NULL;

    if (zai_line_initialized) {
        zai_line_notify(true, frame, state);
        zai_line_close_open(frame, state, 0, NULL, 0);
    }
    /* Normal bailout unwinding closes frames before RSHUTDOWN.
       If cleanup arrives later, the registry is gone but this frame state still needs freeing. */

    zend_hash_destroy(&state->open);
    efree(state);
}

/* Line numbers need not follow opcode order.
   Select the first dispatched instruction on the smallest executable line >= the request, skipping engine scaffolding. */
static zend_op *zai_line_find_opline(zend_op_array *op_array, uint32_t line, uint32_t max_line, uint32_t *resolved) {
    uint32_t best_line = 0;
    uint32_t best_idx = 0;
    bool found = false;

    for (uint32_t i = 0; i < op_array->last; ++i) {
        zend_op *op = &op_array->opcodes[i];

        if (op->opcode == ZEND_OP_DATA || op->opcode == ZEND_RECV || op->opcode == ZEND_RECV_INIT || op->opcode == ZEND_RECV_VARIADIC) {
            continue;
        }
        /* NOPs are compiler placeholders or rewritten declarations, including PHP 7 early binding.
           Skip them as OPcache's NOP-removal pass does. */
        if (op->opcode == ZEND_NOP) {
            continue;
        }
        /* Declarations have their own op_arrays.
           Arming the enclosing declaration would report a hit outside the requested function or class. */
        switch (op->opcode) {
            case ZEND_DECLARE_FUNCTION:
            case ZEND_DECLARE_CLASS:
#ifdef ZEND_DECLARE_CLASS_DELAYED
            case ZEND_DECLARE_CLASS_DELAYED:
#endif
            case ZEND_DECLARE_ANON_CLASS:
#ifdef ZEND_DECLARE_INHERITED_CLASS
            case ZEND_DECLARE_INHERITED_CLASS:
#endif
#ifdef ZEND_DECLARE_INHERITED_CLASS_DELAYED
            case ZEND_DECLARE_INHERITED_CLASS_DELAYED:
#endif
#ifdef ZEND_DECLARE_ANON_INHERITED_CLASS
            case ZEND_DECLARE_ANON_INHERITED_CLASS:
#endif
            case ZEND_DECLARE_LAMBDA_FUNCTION:
                continue;
            default:
                break;
        }
        if (op->opcode >= ZEND_EXT_STMT && op->opcode <= ZEND_TICKS) {
            continue;
        }
#ifdef IS_SMART_BRANCH_JMPZ
        /* Smart branches are consumed by the preceding comparison, not dispatched separately.
           PHP 7 does not encode these flags. */
        if (i > 0 && (op->opcode == ZEND_JMPZ || op->opcode == ZEND_JMPNZ)) {
            uint8_t prev = op_array->opcodes[i - 1].result_type;
            if (prev & (IS_SMART_BRANCH_JMPZ | IS_SMART_BRANCH_JMPNZ)) {
                continue;
            }
        }
#endif

        if (op->lineno < line || (max_line && op->lineno > max_line)) {
            continue;
        }
        if (!found || op->lineno < best_line) {
            found = true;
            best_line = op->lineno;
            best_idx = i;
        }
    }

    if (!found) {
        return NULL;
    }
    *resolved = best_line;

    zend_op *chosen = &op_array->opcodes[best_idx];
    /* The compiler places normal finally entry two slots before the shared body.
       Redirect that site so header hooks cover every entry path.
       This is best-effort after OPcache optimization: removed scaffolding can leave a return/break/continue transfer in the same position, causing its hook to fire on other cleanup paths.
       Keep ordinary JMP sites on their own statements. */
    for (uint32_t hops = 0; chosen->opcode == ZEND_FAST_CALL && hops < op_array->last; ++hops) {
        zend_op *target = OP_JMP_ADDR(chosen, chosen->op1);
        if (target <= chosen || target >= op_array->opcodes + op_array->last) {
            break;
        }
        uint32_t target_idx = (uint32_t)(target - op_array->opcodes);
        uint32_t chosen_idx = (uint32_t)(chosen - op_array->opcodes);
        bool normal_entry = false;
        for (uint32_t t = 0; t < op_array->last_try_catch; ++t) {
            if (op_array->try_catch_array[t].finally_op == target_idx && chosen_idx + 2 == target_idx) {
                normal_entry = true;
                break;
            }
        }
        if (!normal_entry) {
            break;
        }
        chosen = target;
    }
    return chosen;
}

static bool zai_line_arm_def(zai_line_def *def, zend_op_array *op_array, zend_op *opline, bool is_begin) {
    uint32_t uncounted_before = zai_line_hook_uncounted_arms;
    uint32_t bypassed_before = zai_line_hook_jit_bypassed_arms;
    uint32_t trigger_before = zai_line_hook_jit_trigger_arms;
    uint32_t refused_before = zai_line_hook_refused_arms;
    if (!zai_line_hook_arm(op_array, opline)) {
        if (zai_line_hook_refused_arms != refused_before) {
            LOG_LINE_ONCE(WARN,
                          "Line hook could not be armed at %s:%d: "
                          "a sibling worker held the instruction while restoring it and did not finish in time, so this line is not instrumented here",
                          op_array->filename ? ZSTR_VAL(op_array->filename) : "?", (int)opline->lineno);
        }
        return false;
    }
    if (zai_line_hook_jit_trigger_arms != trigger_before) {
        LOG_LINE_ONCE(WARN,
                      "Line hook installed at %s:%d under an opcache.jit trigger that cannot be disabled: "
                      "the function is compiled once it is hot and the compiled code does not consult the instrumented instruction, so the hook stops firing. "
                      "Use opcache.jit=tracing, which is the default",
                      op_array->filename ? ZSTR_VAL(op_array->filename) : "?", (int)opline->lineno);
    }
    if (zai_line_hook_jit_bypassed_arms != bypassed_before) {
        LOG_LINE_ONCE(WARN,
                      "Line hook installed at %s:%d in a function the JIT had already compiled: "
                      "the compiled code does not consult the instrumented instruction, so the hook may not fire there",
                      op_array->filename ? ZSTR_VAL(op_array->filename) : "?", (int)opline->lineno);
    }
    if (zai_line_hook_uncounted_arms != uncounted_before) {
        LOG_LINE_ONCE(WARN,
                      "Line hook arm table exhausted or unavailable: "
                      "%s:%d stays instrumented for the life of the process pool, as there is no way to coordinate its removal with sibling workers",
                      op_array->filename ? ZSTR_VAL(op_array->filename) : "?", (int)opline->lineno);
    }

    zend_ulong site_key = zai_line_hook_opline_key(opline);
    zai_line_site *site = zend_hash_index_find_ptr(&zai_line_armed, site_key);
    if (!site) {
        site = emalloc(sizeof(*site));
        site->opline = opline;
        zend_hash_init(&site->defs, 2, NULL, zai_line_site_entry_free, 0);
        zend_hash_index_add_ptr(&zai_line_armed, site_key, site);
    }

    zai_line_site_entry *entry = zend_hash_index_find_ptr(&site->defs, (zend_ulong)def->id);
    if (!entry) {
        entry = ecalloc(1, sizeof(*entry));
        entry->def = def;
        zend_hash_index_add_ptr(&site->defs, (zend_ulong)def->id, entry);
        zend_hash_index_update_ptr(&def->sites, site_key, (void *)opline);
    }
    entry->is_begin |= is_begin;
    entry->is_end |= !is_begin;
    return true;
}

/* end_op is exclusive, so end callbacks observe the end line's effects.
   Earlier source lines within the range, such as relocated loop increments, remain inside. */
static zend_op *zai_line_find_end_opline(zend_op_array *op_array, zend_op *begin_op, uint32_t end_line, uint32_t *resolved_end_out) {
    uint32_t resolved_end = 0;
    bool have_end = false;

    for (zend_op *op = begin_op; op < op_array->opcodes + op_array->last; ++op) {
        if (op->lineno >= end_line && (!have_end || op->lineno < resolved_end)) {
            have_end = true;
            resolved_end = op->lineno;
        }
    }
    if (!have_end) {
        *resolved_end_out = end_line;
        return NULL;
    }
    *resolved_end_out = resolved_end;

    for (zend_op *op = begin_op; op < op_array->opcodes + op_array->last; ++op) {
        if (op->lineno > resolved_end) {
            return op;
        }
    }
    return NULL; /* runs to the end of the function; the guard hook closes it */
}

/* RT_CONSTANT()'s first argument changed meaning in 7.3: the op_array before, the opline from then on. */
#if PHP_VERSION_ID < 70300
# define ZAI_LINE_RT_CONSTANT(op_array, op, node) RT_CONSTANT((op_array), (node))
#else
# define ZAI_LINE_RT_CONSTANT(op_array, op, node) RT_CONSTANT((op), (node))
#endif

/* Arm jump successors outside the range to close it before later work runs.
   pass_two rewrites GOTO/BRK/CONT to JMP.
   FAST_RET targets are handled separately by zai_line_arm_finally_returns(). */
static void zai_line_each_jump_target(zend_op_array *op_array, zend_op *op, void (*visit)(zend_op *, void *), void *ctx) {
    switch (op->opcode) {
        case ZEND_JMP:
        case ZEND_FAST_CALL:
            visit(OP_JMP_ADDR(op, op->op1), ctx);
            return;

        case ZEND_JMPZ:
        case ZEND_JMPNZ:
        case ZEND_JMPZ_EX:
        case ZEND_JMPNZ_EX:
        case ZEND_JMP_SET:
        case ZEND_COALESCE:
        case ZEND_FE_RESET_R:
        case ZEND_FE_RESET_RW:
        case ZEND_ASSERT_CHECK:
#ifdef ZEND_JMP_NULL
        case ZEND_JMP_NULL:
#endif
#ifdef ZEND_BIND_INIT_STATIC_OR_JMP
        case ZEND_BIND_INIT_STATIC_OR_JMP:
#endif
#ifdef ZEND_JMP_FRAMELESS
        case ZEND_JMP_FRAMELESS:
#endif
            visit(OP_JMP_ADDR(op, op->op2), ctx);
            return;

#ifdef ZEND_JMPZNZ
        /* JMPZNZ has two targets and exists through PHP 8.1. */
        case ZEND_JMPZNZ:
            visit(OP_JMP_ADDR(op, op->op2), ctx);
            visit(ZEND_OFFSET_TO_OPLINE(op, op->extended_value), ctx);
            return;
#endif

        /* An unmatched catch jumps to the next handler.
           Before 7.3, extended_value holds the relative target and result.num marks the last catch; from 7.3, the target is op2 and the flag is in extended_value. */
        case ZEND_CATCH:
#if PHP_VERSION_ID >= 70300
            if (!(op->extended_value & ZEND_LAST_CATCH)) {
                visit(OP_JMP_ADDR(op, op->op2), ctx);
            }
#elif PHP_VERSION_ID >= 70100
            if (!op->result.num) {
                visit(ZEND_OFFSET_TO_OPLINE(op, op->extended_value), ctx);
            }
#else
            /* 7.0 stores an absolute opline *index* there; 7.1 switched it to a relative byte offset. */
            if (!op->result.num) {
                visit(op_array->opcodes + op->extended_value, ctx);
            }
#endif
            return;

        case ZEND_FE_FETCH_R:
        case ZEND_FE_FETCH_RW:
            visit(ZEND_OFFSET_TO_OPLINE(op, op->extended_value), ctx);
            return;

#if PHP_VERSION_ID >= 70200 /* SWITCH_* landed in 7.2; only RT_CONSTANT()'s spelling changed in 7.3 */
        case ZEND_SWITCH_LONG:
        case ZEND_SWITCH_STRING:
# ifdef ZEND_MATCH
        case ZEND_MATCH:
# endif
        {
            HashTable *jumptable = Z_ARRVAL_P(ZAI_LINE_RT_CONSTANT(op_array, op, op->op2));
            zval *target;
            ZEND_HASH_FOREACH_VAL(jumptable, target) {
                visit(ZEND_OFFSET_TO_OPLINE(op, Z_LVAL_P(target)), ctx);
            }
            ZEND_HASH_FOREACH_END();
            visit(ZEND_OFFSET_TO_OPLINE(op, op->extended_value), ctx);
            return;
        }
#endif

        default:
            return;
    }
#if PHP_VERSION_ID >= 70300
    (void)op_array;
#endif
}

typedef struct {
    zai_line_def *def;
    zend_op_array *op_array;
    zend_op *begin_op;
    zend_op *end_op;     /* exclusive; NULL means "to the end of the function" */
    uint32_t first_line; /* the resolved source range, which is what decides membership */
    uint32_t last_line;
} zai_line_exit_ctx;

/* FAST_RET resumes after the FAST_CALL that entered its finally.
   Enumerate those static continuations to close ranges within finally at their actual exits. */
static void zai_line_arm_finally_returns(zend_op_array *op_array, zend_op *finally_op, void (*visit)(zend_op *, void *), void *ctx) {
    zend_op *last = op_array->opcodes + op_array->last;
    for (zend_op *op = op_array->opcodes; op < last; ++op) {
        if (op->opcode == ZEND_FAST_CALL && OP_JMP_ADDR(op, op->op1) == finally_op && op + 1 < last) {
            visit(op + 1, ctx);
        }
    }
}

/* Follow unconditional transfers before testing range membership: scaffolding carries its construct's line, not its destination's.
   Cap hops at the opcode count to handle jump cycles. */
static zend_op *zai_line_effective_target(const zend_op_array *op_array, zend_op *target) {
    for (uint32_t hops = 0; hops < op_array->last; ++hops) {
        if (target->opcode == ZEND_JMP) {
            target = OP_JMP_ADDR(target, target->op1);
            continue;
        }
        /* An inner finally may resume at an outer finally's FAST_CALL.
           Use the body's location to determine membership. */
        if (target->opcode == ZEND_FAST_CALL) {
            target = OP_JMP_ADDR(target, target->op1);
            continue;
        }
        break;
    }
    return target;
}

/* Only unconditional transfers prevent fallthrough; conditional jumps can also leave a range through the next instruction. */
static bool zai_line_falls_through(const zend_op *op) {
    switch (op->opcode) {
        case ZEND_JMP:
        case ZEND_RETURN:
        case ZEND_RETURN_BY_REF:
        case ZEND_GENERATOR_RETURN:
        case ZEND_THROW:
#ifdef ZEND_EXIT
        /* Removed in 8.4, where exit() became an ordinary function. */
        case ZEND_EXIT:
#endif
        case ZEND_FAST_RET:
#ifdef ZEND_JMPZNZ
        case ZEND_JMPZNZ:
#endif
#ifdef ZEND_MATCH_ERROR
        case ZEND_MATCH_ERROR:
#endif
            return false;
        default:
            return true;
    }
}

/* Membership includes the opcode window OR the source range, after resolving compiler transfers.
   Relocated loop conditions can lie beyond the window but within the source range; a body-only range can contain instructions attributed to its header. */
static bool zai_line_in_range(const zai_line_exit_ctx *ctx, const zend_op *op) {
    if (op >= ctx->begin_op && (!ctx->end_op || op < ctx->end_op)) {
        return true;
    }
    return op->lineno >= ctx->first_line && op->lineno <= ctx->last_line;
}

static void zai_line_visit_exit(zend_op *target, void *ctx_) {
    zai_line_exit_ctx *ctx = ctx_;
    target = zai_line_effective_target(ctx->op_array, target);
    if (zai_line_in_range(ctx, target)) {
        return;
    }
    zai_line_arm_def(ctx->def, ctx->op_array, target, false);
}

/* Share one guard per (opcodes, scope).
   zai_hook filters entry by resolved_scope, so trait methods flattened into different classes need separate guards even when their opcodes are shared. */
static bool zai_line_install_guard(zai_line_def *def, zend_op_array *op_array) {
    zend_ulong key = (zend_ulong)(uintptr_t)op_array->opcodes;

    HashTable *set = zend_hash_index_find_ptr(&zai_line_guards, key);
    if (!set) {
        set = emalloc(sizeof(*set));
        zend_hash_init(set, 1, NULL, zai_line_guard_free, 0);
        zend_hash_index_add_ptr(&zai_line_guards, key, set);
    }

    zend_ulong scope_key = (zend_ulong)(uintptr_t)op_array->scope; /* 0 for functions and closures */
    zai_line_guard *guard = zend_hash_index_find_ptr(set, scope_key);
    if (!guard) {
        zend_long id = zai_hook_install_resolved_generator(
            (zend_function *)op_array, NULL, zai_line_generator_resume, zai_line_generator_yield, zai_line_guard_end,
            ZAI_HOOK_AUX_UNUSED, sizeof(zai_line_frame *));
        if (id < 0) {
            return false;
        }
        guard = ecalloc(1, sizeof(*guard));
        guard->id = id;
        guard->address = zai_hook_install_address((zend_function *)op_array);
        guard->opcodes_key = key;
        guard->scope_key = scope_key;
        guard->refs = 1; /* the set's own claim */
        zend_hash_index_add_ptr(set, scope_key, guard);
    }

    /* Keyed by the record's own address: it is stable for the guard's life, so no reverse index is needed. */
    if (zend_hash_index_add_ptr(&def->guards, (zend_ulong)(uintptr_t)guard, guard)) {
        ++guard->defs;
        ++guard->refs;
    }
    return true;
}

#if PHP_VERSION_ID >= 80000
/* Find classes missed by PHP 8 declaration observers: anonymous classes and named classes declared inside functions, including runtime-bound classes.
   Traverse their declaration opcodes even if already linked. */
static zend_class_entry *zai_line_declared_class(const zend_op *op) {
    zval *lcname;

    switch (op->opcode) {
        case ZEND_DECLARE_ANON_CLASS:
            /* op1 is the mangled name the class was added to the class table under at compile time. */
            return zend_hash_find_ptr(EG(class_table), Z_STR_P(RT_CONSTANT(op, op->op1)));

        case ZEND_DECLARE_CLASS:
            /* op1 is lcname, with the runtime definition key as the literal right after it.
               The class lives under the rtd key until do_bind_class() renames the bucket to lcname, so try both, exactly as it does. */
            lcname = RT_CONSTANT(op, op->op1);
            zend_class_entry *ce = zend_hash_find_ptr(EG(class_table), Z_STR_P(lcname + 1));
            return ce ? ce : zend_hash_find_ptr(EG(class_table), Z_STR_P(lcname));

        default:
            return NULL;
    }
}
#endif

static bool zai_line_arm_range(zai_line_def *def, zend_op_array *op_array, zend_op *opline, uint32_t resolved) {
    zai_line_site *site = zend_hash_index_find_ptr(&zai_line_armed, zai_line_hook_opline_key(opline));
    zai_line_site_entry *entry = site ? zend_hash_index_find_ptr(&site->defs, (zend_ulong)def->id) : NULL;
    if (!entry || !zai_line_install_guard(def, op_array)) {
        return false;
    }
    if (entry->has_range) {
        return true;
    }
    entry->has_range = true;
    /* Even with no static end site the range still closes, just at frame exit rather than at end_op. */
    uint32_t resolved_end = def->end_line;
    zend_op *end_op = zai_line_find_end_opline(op_array, opline, def->end_line, &resolved_end);
    if (end_op && end_op != opline) {
        zai_line_arm_def(def, op_array, end_op, false);
    }

    zai_line_exit_ctx ctx = {
        .def = def,
        .op_array = op_array,
        .begin_op = opline,
        .end_op = end_op,
        .first_line = resolved,
        .last_line = resolved_end
    };
    zend_op *range_end = end_op ? end_op : op_array->opcodes + op_array->last;
    zend_op *last = op_array->opcodes + op_array->last;

    /* Scan all members, including relocated loop conditions beyond end_op whose source lines still belong to the range. */
    for (zend_op *op = op_array->opcodes; op < last; ++op) {
        if (!zai_line_in_range(&ctx, op)) {
            continue;
        }
        zai_line_each_jump_target(op_array, op, zai_line_visit_exit, &ctx);
        /* Falling off the end of the range leaves it just as a jump does. */
        if (zai_line_falls_through(op) && op + 1 < last) {
            zai_line_visit_exit(op + 1, &ctx);
        }
    }

    /* Exception dispatch reaches catch/finally through try_catch_array, bypassing ordinary jump edges. */
    for (uint32_t t = 0; t < op_array->last_try_catch; ++t) {
        const zend_try_catch_element *tc = &op_array->try_catch_array[t];
        uint32_t handler = tc->finally_op ? tc->finally_op : tc->catch_op;
        if (!handler) {
            continue;
        }
        /* The try region is [try_op, first handler).
           Only a try the range can actually throw from matters, i.e. one whose region overlaps it. */
        if (op_array->opcodes + tc->try_op >= range_end || op_array->opcodes + handler <= opline) {
            continue;
        }
        if (tc->catch_op) {
            zai_line_visit_exit(op_array->opcodes + tc->catch_op, &ctx);
        }
        if (tc->finally_op) {
            zai_line_visit_exit(op_array->opcodes + tc->finally_op, &ctx);
        }
    }

    /* And, for a range sitting *inside* a finally, where that finally can return to. */
    for (uint32_t t = 0; t < op_array->last_try_catch; ++t) {
        const zend_try_catch_element *tc = &op_array->try_catch_array[t];
        if (!tc->finally_op) {
            continue;
        }
        zend_op *finally_op = op_array->opcodes + tc->finally_op;
        if (finally_op >= range_end || (tc->finally_end && op_array->opcodes + tc->finally_end <= opline)) {
            continue;
        }
        zai_line_arm_finally_returns(op_array, finally_op, zai_line_visit_exit, &ctx);
    }
    return true;
}

/* Resolve in two passes per file: find the smallest executable line >= the request across candidate op_arrays, then arm only that line.
   This avoids independently sliding enclosing functions or file scope to unrelated code.
   Visit nested closures and declared classes as well. */
static bool zai_line_resolve_op_array(zai_line_def *def, zend_op_array *op_array, HashTable *by_file, bool arm) {
    bool armed = false;

    if (op_array->type != ZEND_USER_FUNCTION || !op_array->filename || !zai_hook_match_filepath(op_array->filename, def->file)) {
        return false;
    }

    /* Filter by line_end only: forward resolution may select a function that starts after the requested line. */
    if (def->line <= op_array->line_end) {
        uint32_t resolved;
        zend_op *opline = NULL;

        if (!arm) {
            /* Track the minimum per file, so suffix-matched files resolve independently while their enclosing op_arrays share one result. */
            if (zai_line_find_opline(op_array, def->line, 0, &resolved)) {
                zval *min = zend_hash_find(by_file, op_array->filename);
                if (!min || (uint32_t)Z_LVAL_P(min) > resolved) {
                    zval zv;
                    ZVAL_LONG(&zv, resolved);
                    zend_hash_update(by_file, op_array->filename, &zv);
                }
            }
        } else {
            zval *min = zend_hash_find(by_file, op_array->filename);
            if (min) {
                opline = zai_line_find_opline(op_array, def->line, (uint32_t)Z_LVAL_P(min), &resolved);
            }
        }

        if (opline && zai_line_arm_def(def, op_array, opline, true)) {
            LOG(DEBUG, "Armed line hook %d at %s:%d (requested %d)", (int)def->id, ZSTR_VAL(op_array->filename), (int)resolved, (int)def->line);
            armed = true;

            if (def->eager_range) {
                zai_line_arm_range(def, op_array, opline, resolved);
            }
        }
    }

#if PHP_VERSION_ID >= 80100
    /* PHP 8.1+ stores nested closures in dynamic_func_defs.
       Earlier versions use runtime-definition keys in the function table, reached through the location map. */
    for (uint32_t i = 0; i < op_array->num_dynamic_func_defs; ++i) {
        armed |= zai_line_resolve_op_array(def, op_array->dynamic_func_defs[i], by_file, arm);
    }
#endif

#if PHP_VERSION_ID >= 80000

    /* PHP 7 resolves these classes through EG(class_table).
       Revisiting mapped classes is safe: arming is idempotent and the per-file minimum is order independent. */
    for (uint32_t i = 0; i < op_array->last; ++i) {
        zend_class_entry *ce = zai_line_declared_class(&op_array->opcodes[i]);
        if (!ce || ce->type != ZEND_USER_CLASS) {
            continue;
        }

        zend_function *fn;
        ZEND_HASH_FOREACH_PTR(&ce->function_table, fn) {
            /* Inherited and trait-flattened methods are filtered by the filename check on the way in, but skipping foreign scopes here saves the call. */
            if (fn->common.scope == ce && fn->type == ZEND_USER_FUNCTION) {
                armed |= zai_line_resolve_op_array(def, &fn->op_array, by_file, arm);
            }
        }
        ZEND_HASH_FOREACH_END();

# if PHP_VERSION_ID >= 80400
        /* Property hooks are not in the function table - see zai_hook_resolve_class(). */
        zend_property_info *prop_info;
        ZEND_HASH_FOREACH_PTR(&ce->properties_info, prop_info) {
            if (!prop_info->hooks || prop_info->ce != ce) {
                continue;
            }
            for (uint32_t h = 0; h < ZEND_PROPERTY_HOOK_COUNT; ++h) {
                if (prop_info->hooks[h] && prop_info->hooks[h]->type == ZEND_USER_FUNCTION) {
                    armed |= zai_line_resolve_op_array(def, &prop_info->hooks[h]->op_array, by_file, arm);
                }
            }
        }
        ZEND_HASH_FOREACH_END();
# endif
    }
#endif

    return armed;
}

/* PHP 8.0 needs a symbol-table fallback for functions and methods missed by declaration observers.
   Resolution filters filenames and visits locally declared classes. */
#if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80100
static bool zai_line_resolve_symbol_tables(zai_line_def *def, HashTable *by_file, bool arm) {
    bool armed = false;

    zend_function *fn;
    ZEND_HASH_FOREACH_PTR(EG(function_table), fn) {
        if (fn->type == ZEND_USER_FUNCTION && fn->op_array.filename) {
            armed |= zai_line_resolve_op_array(def, &fn->op_array, by_file, arm);
        }
    }
    ZEND_HASH_FOREACH_END();

    zend_class_entry *ce;
    ZEND_HASH_FOREACH_PTR(EG(class_table), ce) {
        if (!ce || ce->type != ZEND_USER_CLASS) {
            continue;
        }
        ZEND_HASH_FOREACH_PTR(&ce->function_table, fn) {
            /* Inherited and trait-flattened methods are filtered by the filename check inside, but skipping foreign scopes here saves the call. */
            if (fn->common.scope == ce && fn->type == ZEND_USER_FUNCTION) {
                armed |= zai_line_resolve_op_array(def, &fn->op_array, by_file, arm);
            }
        }
        ZEND_HASH_FOREACH_END();
    }
    ZEND_HASH_FOREACH_END();

    return armed;
}
#endif

#if PHP_VERSION_ID >= 70400
static void zai_line_preloaded_add(zend_op_array *op_array) {
    if (op_array->type != ZEND_USER_FUNCTION || !op_array->filename) {
        return;
    }
    zend_llist *list = zend_hash_find_ptr(&zai_line_preloaded, op_array->filename);
    if (!list) {
        list = emalloc(sizeof(*list));
        zend_llist_init(list, sizeof(zend_op_array *), NULL, 0);
        zend_hash_add_ptr(&zai_line_preloaded, op_array->filename, list);
    }
    zend_llist_add_element(list, &op_array);
}

static void zai_line_preloaded_list_free(zval *zv) {
    zend_llist *list = Z_PTR_P(zv);
    zend_llist_destroy(list);
    efree(list);
}

/* Preloaded declarations bypass this request's observers.
   Index ZEND_ACC_PRELOADED functions once by filename, so later resolution scans matching files without revisiting every declaration. */
static void zai_line_preloaded_build(void) {
    if (zai_line_preloaded_built) {
        return;
    }
    zai_line_preloaded_built = true;
    zend_hash_init(&zai_line_preloaded, 8, NULL, zai_line_preloaded_list_free, 0);

    zend_function *fn;
    ZEND_HASH_FOREACH_PTR(EG(function_table), fn) {
        if (fn->type == ZEND_USER_FUNCTION && (fn->common.fn_flags & ZEND_ACC_PRELOADED)) {
            zai_line_preloaded_add(&fn->op_array);
        }
    }
    ZEND_HASH_FOREACH_END();

    zend_class_entry *ce;
    ZEND_HASH_FOREACH_PTR(EG(class_table), ce) {
        if (!ce || ce->type != ZEND_USER_CLASS || !(ce->ce_flags & ZEND_ACC_PRELOADED)) {
            continue;
        }
        ZEND_HASH_FOREACH_PTR(&ce->function_table, fn) {
            if (fn->common.scope == ce && fn->type == ZEND_USER_FUNCTION) {
                zai_line_preloaded_add(&fn->op_array);
            }
        }
        ZEND_HASH_FOREACH_END();

# if PHP_VERSION_ID >= 80400
        zend_property_info *prop_info;
        ZEND_HASH_FOREACH_PTR(&ce->properties_info, prop_info) {
            if (!prop_info->hooks || prop_info->ce != ce) {
                continue;
            }
            for (uint32_t h = 0; h < ZEND_PROPERTY_HOOK_COUNT; ++h) {
                if (prop_info->hooks[h] && prop_info->hooks[h]->type == ZEND_USER_FUNCTION) {
                    zai_line_preloaded_add(&prop_info->hooks[h]->op_array);
                }
            }
        }
        ZEND_HASH_FOREACH_END();
# endif
    }
    ZEND_HASH_FOREACH_END();
}

static bool zai_line_resolve_preloaded(zai_line_def *def, HashTable *by_file, bool arm) {
    bool armed = false;
    zai_line_preloaded_build();

    zend_string *filename;
    zend_llist *list;
    ZEND_HASH_FOREACH_STR_KEY_PTR(&zai_line_preloaded, filename, list) {
        if (!filename || !zai_hook_match_filepath(filename, def->file)) {
            continue;
        }
        for (zend_llist_element *el = list->head; el; el = el->next) {
            armed |= zai_line_resolve_op_array(def, *(zend_op_array **)el->data, by_file, arm);
        }
    }
    ZEND_HASH_FOREACH_END();

    return armed;
}
#endif

/* Resolve through the filename location map and live file scopes; extra is a newly compiled file scope.
   PHP 7's compile-time table delta includes runtime-key declarations.
   PHP 8 supplements top-level observers by traversing nested closures and classes.
   Preloaded declarations use a separate file index; PHP 8.0 also needs the symbol-table fallback. */
static bool zai_line_resolve_pass(zai_line_def *def, zend_op_array *extra, HashTable *by_file, bool arm) {
    bool armed = false;

    if (extra) {
        armed |= zai_line_resolve_op_array(def, extra, by_file, arm);
    }

    for (zend_execute_data *ex = EG(current_execute_data); ex; ex = ex->prev_execute_data) {
        if (ex->func && ZEND_USER_CODE(ex->func->type) && !ex->func->op_array.function_name && &ex->func->op_array != extra) {
            armed |= zai_line_resolve_op_array(def, &ex->func->op_array, by_file, arm);
        }
    }

    /* The resolver's compile hooks populate the location map before this outer hook re-resolves the file. */
    zend_string *filename;
    zai_function_location_entry *entry;
    ZEND_HASH_FOREACH_STR_KEY_PTR(zai_hook_function_location_map(), filename, entry) {
        if (!filename || !zai_hook_match_filepath(filename, def->file)) {
            continue;
        }
        for (uint32_t i = 0; i < entry->size; ++i) {
            armed |= zai_line_resolve_op_array(def, &entry->functions[i]->op_array, by_file, arm);
        }
    }
    ZEND_HASH_FOREACH_END();

#if PHP_VERSION_ID >= 70400
    /* Always include preloaded files.
       A matching map entry does not cover other preloaded filenames sharing the requested suffix. */
    armed |= zai_line_resolve_preloaded(def, by_file, arm);

# if PHP_VERSION_ID >= 80000 && PHP_VERSION_ID < 80100
    /* PHP 8.0 lacks dynamic_func_defs and observes only top-level declarations, so it still needs the full symbol-table scan. */
    armed |= zai_line_resolve_symbol_tables(def, by_file, arm);
# endif
#endif

    return armed;
}

/* Resolve one executable line per file, then arm every matching op_array.
   Repeated resolution is idempotent per (opline, def). */
static bool zai_line_resolve(zai_line_def *def, zend_op_array *extra) {
    HashTable by_file; /* filename -> smallest executable lineno >= def->line in that file */
    zend_hash_init(&by_file, 4, NULL, NULL, 0);

    zai_line_resolve_pass(def, extra, &by_file, false);
    bool armed = zend_hash_num_elements(&by_file) && zai_line_resolve_pass(def, extra, &by_file, true);

    zend_hash_destroy(&by_file);
    return armed;
}

/* Re-resolve definitions matching a newly compiled filename; arming is idempotent per (opline, def). */
void zai_line_hooks_file_compiled(zend_op_array *op_array) {
    if (!zai_line_initialized || !zend_hash_num_elements(&zai_line_defs) || !op_array->filename) {
        return;
    }

    zai_line_def *def;
    ZEND_HASH_FOREACH_PTR(&zai_line_defs, def) {
        if (zai_hook_match_filepath(op_array->filename, def->file)) {
            /* Full rescan rather than just this op_array: the functions the file declares are early-bound into the function table by now, and are not reachable through its dynamic_func_defs. */
            zai_line_resolve(def, op_array);
        }
    }
    ZEND_HASH_FOREACH_END();
}

/* Opcode storage is already freed.
   Drop its sites without dereferencing it; keep definitions for future includes. */
static void zai_line_op_array_dtor(zend_op_array *op_array) {
    if (!zai_line_initialized) {
        return;
    }

    uintptr_t low = (uintptr_t)op_array->opcodes;
    uintptr_t high = low + (uintptr_t)op_array->last * sizeof(zend_op);

    zend_ulong key;
    zai_line_site *site;
    ZEND_HASH_FOREACH_NUM_KEY_PTR(&zai_line_armed, key, site) {
        uintptr_t addr = (uintptr_t)site->opline;
        if (addr >= low && addr < high) {
            zend_hash_index_del(&zai_line_armed, key);
        }
    }
    ZEND_HASH_FOREACH_END();

    zend_hash_index_del(&zai_line_guards, (zend_ulong)low);
}

void zai_line_hooks_rinit(void) {
    zend_hash_init(&zai_line_defs, 8, NULL, zai_line_def_free, 0);
    zend_hash_init(&zai_line_armed, 8, NULL, zai_line_site_free, 0);
    zend_hash_init(&zai_line_guards, 8, NULL, zai_line_nested_ht_free, 0);
    zend_hash_init(&zai_line_pending_drop, 8, NULL, NULL, 0);
    zai_line_dispatch_depth = 0;
    zai_line_next_id = -1;
    zai_line_initialized = true;
    zai_line_hook_set_handler(zai_line_dispatch);
    zai_line_hook_set_op_array_dtor(zai_line_op_array_dtor);
}

void zai_line_hooks_rshutdown(void) {
    if (!zai_line_initialized) {
        return;
    }
    zai_line_initialized = false;

    zai_line_site *site;
    ZEND_HASH_FOREACH_PTR(&zai_line_armed, site) {
        zai_line_hook_disarm((zend_op *)site->opline);
    }
    ZEND_HASH_FOREACH_END();

    zai_line_hook_rshutdown();

    zend_hash_destroy(&zai_line_pending_drop);
    zend_hash_destroy(&zai_line_guards);
#if PHP_VERSION_ID >= 70400
    if (zai_line_preloaded_built) {
        zend_hash_destroy(&zai_line_preloaded);
        zai_line_preloaded_built = false;
    }
#endif
    zend_hash_destroy(&zai_line_armed);
    zend_hash_destroy(&zai_line_defs);
}

bool zai_line_hooks_remove(zend_long id) {
    if (!zai_line_initialized || id >= 0) {
        return false;
    }

    zai_line_def *def = zend_hash_index_find_ptr(&zai_line_defs, (zend_ulong)id);
    if (!def) {
        return true; /* the id is ours, it is simply already gone */
    }

    /* Stop new begins immediately; retain arms and guards until existing ranges close at their own end sites. */
    def->removed = true;
    if (!def->open_count) {
        zai_line_def_drop(id);
    }
    return true;
}

/* Ranges must nest or be disjoint when their filename suffixes could name the same file.
   Check requested source lines during installation, while overlap errors can still be reported to the caller. */
const char *zai_line_hooks_conflict(zend_string *file, uint32_t line, uint32_t end_line) {
    if (!zai_line_initialized) {
        return NULL;
    }
    if (line == end_line) {
        /* A single-line probe cannot partially overlap a range. */
        return NULL;
    }

    zai_line_def *other;
    ZEND_HASH_FOREACH_PTR(&zai_line_defs, other) {
        /* Either may be the shorter spelling, so the suffix match has to be tried both ways round. */
        if (!zai_hook_match_filepath(other->file, file) && !zai_hook_match_filepath(file, other->file)) {
            continue;
        }
        bool starts_inside = line >= other->line && line <= other->end_line;
        bool ends_inside = end_line >= other->line && end_line <= other->end_line;
        bool contains_other = line <= other->line && end_line >= other->end_line;
        if (starts_inside != ends_inside && !contains_other) {
            return "Line hook range partially overlaps an existing range in the same file";
        }
    }
    ZEND_HASH_FOREACH_END();

    return NULL;
}

zend_long zai_line_hooks_install(zend_string *file, uint32_t line, uint32_t end_line,
                                zai_line_hooks_begin begin, zai_line_hooks_end end,
                                bool eager_range, zai_line_hooks_aux aux, size_t dynamic) {
    return zai_line_hooks_install_generator(file, line, end_line, begin, NULL, NULL, end, eager_range, aux, dynamic);
}

zend_long zai_line_hooks_install_generator(zend_string *file, uint32_t line, uint32_t end_line,
                                          zai_line_hooks_begin begin, zai_line_hooks_frame_event suspend, zai_line_hooks_frame_event resume, zai_line_hooks_end end,
                                          bool eager_range, zai_line_hooks_aux aux, size_t dynamic) {
    if (!zai_line_initialized) {
        if (aux.dtor) {
            aux.dtor(aux.data);
        }
        return 0;
    }

    zai_line_def *def = ecalloc(1, sizeof(*def));
    zend_hash_init(&def->guards, 1, NULL, zai_line_guard_claim_free, 0);
    zend_hash_init(&def->sites, 2, NULL, NULL, 0);
    def->id = zai_line_next_id--;
    def->file = zend_string_copy(file);
    def->line = line;
    def->end_line = end_line;
    def->begin = begin;
    def->end = end;
    def->suspend = suspend;
    def->resume = resume;
    def->eager_range = eager_range;
    def->aux = aux;
    def->dynamic = dynamic;

    zend_hash_index_add_new_ptr(&zai_line_defs, (zend_ulong)def->id, def);
    zai_line_resolve(def, NULL);
    return def->id;
}
