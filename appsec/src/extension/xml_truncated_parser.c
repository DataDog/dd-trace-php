// Unless explicitly stated otherwise all files in this repository are
// dual-licensed under the Apache-2.0 License or BSD-3-Clause License.
//
// This product includes software developed at Datadog
// (https://www.datadoghq.com/). Copyright 2021 Datadog, Inc.

// clang-format off
#include "xml_truncated_parser.h"
#include "attributes.h"
#include "logging.h"
#include "php_compat.h"
#include <libxml/SAX2.h>
#include <libxml/entities.h>
#include <libxml/parser.h>
#include <libxml/parserInternals.h>
#include <libxml/xmlmemory.h>
#include <string.h>
#include <zend_API.h>
// clang-format on

#define STACK_INITIAL_CAPACITY 16
typedef struct {
    zval **items;
    size_t size;
    size_t capacity;
} _ptr_stack;

typedef struct {
    zval root;                    // The root result, OWNING
    _ptr_stack stack;             // Stack of zval* pointers
    size_t max_depth;             // Maximum allowed depth
    size_t suppressed;            // Depth counter when suppressing
    zend_string *text_buf;        // Accumulated text buffer, OWNING
    xmlParserCtxtPtr parser_ctxt; // libxml2 parser context, NON-OWNING
} _xml_parse_ctx;

// libxml2 memory allocator wrappers
static void _dd_xml_free(void *mem);
static void *_dd_xml_malloc(size_t size);
static void *_dd_xml_realloc(void *mem, size_t size);
static char *_dd_xml_strdup(const char *str);

// Stack operations
static void _stack_init(_ptr_stack *s);
static void _stack_destroy(_ptr_stack *s);
static void _stack_push(_ptr_stack *s, zval *ptr);
static zval *nullable _stack_pop(_ptr_stack *s);
static zval *nullable _stack_top(_ptr_stack *s);

// Parser context management
static void _ctx_init(_xml_parse_ctx *ctx, size_t max_depth);
static void _ctx_destroy(_xml_parse_ctx *ctx);
static void _ctx_flush_text(_xml_parse_ctx *ctx);
static void _zend_string_release_cleanup(zend_string **str);

// Forward declarations - SAX callbacks
static xmlParserInputPtr _sax_resolve_entity(
    void *user_ctx, const xmlChar *publicId, const xmlChar *systemId);
static xmlEntityPtr _sax_get_entity(void *user_ctx, const xmlChar *name);
static void _sax_reference(void *user_ctx, const xmlChar *name);
static void _sax_start_element(
    void *user_ctx, const xmlChar *name, const xmlChar **atts);
static void _sax_end_element(void *user_ctx, const xmlChar *name);
static void _sax_characters(void *user_ctx, const xmlChar *ch, int len);
static void _sax_cdata_block(void *user_ctx, const xmlChar *value, int len);

static bool _xml_initialized = false;

/**
 * This file uses a statically-linked libxml2 with a custom allocator that
 * uses PHP's emalloc/efree. This is request-scoped memory, which means any
 * memory not freed before request end will cause use-after-free bugs.
 *
 * libxml2's xmlCleanupParser() frees global state allocated with xmlMalloc.
 * We analyzed each cleanup function to verify our usage doesn't trigger
 * persistent allocations:
 *
 * 1. xmlCleanupCharEncodingHandlers - Frees custom encoding handlers.
 *    SAFE: Handlers only allocated via xmlNewCharEncodingHandler() which
 *    is never called during parsing. We don't register custom handlers.
 *
 * 2. xmlCleanupEncodingAliases - Frees encoding alias table.
 *    SAFE: Aliases only added via xmlAddEncodingAlias() which is never
 *    called during parsing. We don't add encoding aliases.
 *
 * 3. xmlCatalogCleanup - Frees XML catalog data.
 *    SAFE: Disabled at compile time (LIBXML2_WITH_CATALOG=OFF).
 *
 * 4. xmlSchemaCleanupTypes / xmlRelaxNGCleanupTypes - Schema type tables.
 *    SAFE: Disabled at compile time (LIBXML2_WITH_SCHEMAS=OFF).
 *
 * 5. xmlCleanupInputCallbacks / xmlCleanupOutputCallbacks - I/O callbacks.
 *    SAFE: These only null out function pointers in static tables, no
 *    memory is freed. Output disabled (LIBXML2_WITH_OUTPUT=OFF).
 *
 * 6. xmlCleanupDictInternal / xmlCleanupRandom - Mutex cleanup only.
 *    SAFE: No memory allocation, just pthread mutex destruction. The mutexes
 *    (xmlDictMutex, xmlThrDefMutex) are statically allocated and initialized
 *    with pthread_mutex_init(), which doesn't use our custom allocator.
 *
 * 7. xmlCleanupGlobalsInternal - Cleans global error state.
 *    HANDLED: Calls xmlResetError(&xmlLastError) which frees error strings.
 *    We call xmlResetLastError() after each parse to clean this up.
 *    Thread-local storage for per-thread globals uses system calloc(), not
 *    our custom allocator, so it's also safe.
 *
 * 8. xmlCleanupMemoryInternal - Mutex cleanup only.
 *    SAFE: No memory allocation.
 *
 * NOTE: Thread support (LIBXML2_WITH_THREADS) is enabled for ZTS PHP builds.
 * This is safe because all thread-related state (mutexes, thread-local storage
 * keys, pthread_t values) are either statically allocated or use the system
 * allocator, not our custom PHP request-scoped allocator.
 *
 */

// ***************************************************************************
// Public API
// ***************************************************************************

bool dd_xml_parser_startup(void)
{
    if (_xml_initialized) {
        return true;
    }

    // this must be called before any other libxml2 functions
    if (xmlMemSetup(_dd_xml_free, _dd_xml_malloc, _dd_xml_realloc,
            _dd_xml_strdup) != 0) {
        mlog(dd_log_warning, "Failed to set up libxml2 memory allocator");
        return false;
    }

    // initialize the library; this doesn't actually allocate memory
    LIBXML_TEST_VERSION

    _xml_initialized = true;
    mlog(dd_log_trace, "Successfully initialized static libxml2");
    return true;
}

void dd_xml_parser_shutdown(void)
{
    if (_xml_initialized) {
        xmlCleanupParser();
        _xml_initialized = false;
    }
}

/*
 * XML to zval transformation format:
 *
 * Each element becomes {"tagname": [contents...]}, where contents is an array
 * containing: attributes object (if any), text nodes, and child elements.
 * Attributes are prefixed with '@'. Whitespace-only text nodes are omitted.
 *
 * Example: <note><to attr="x">text</to></note>
 * Result:  {"note": [{"to": [{"@attr": "x"}, "text"]}]}
 */
zval dd_parse_xml_truncated(
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    const char *nonnull xml_data, size_t xml_len, int max_depth)
{
    zval result;
    ZVAL_NULL(&result);

    if (xml_len == 0 || xml_len > INT_MAX) {
        return result;
    }

    if (!_xml_initialized) {
        mlog(dd_log_debug, "libxml2 not initialized, cannot parse XML");
        return result;
    }

    xmlSAXHandler sax_handler = {
        .initialized = XML_SAX2_MAGIC,
        .resolveEntity = _sax_resolve_entity,
        .getEntity = _sax_get_entity,
        .startElement = _sax_start_element,
        .endElement = _sax_end_element,
        .reference = _sax_reference,
        .characters = _sax_characters,
        .cdataBlock = _sax_cdata_block,
    };

    __attribute__((cleanup(_ctx_destroy))) _xml_parse_ctx ctx;
    _ctx_init(&ctx, (size_t)max_depth);

    // - XML_PARSE_NOENT: Substitute entities (needed for internal entities)
    // - XML_PARSE_NONET: Forbid network access
    // - XML_PARSE_NO_XXE: Disable loading of external entities (XXE protection)
    // - XML_PARSE_RECOVER: Try to recover from errors (for truncated XML)
    // - XML_PARSE_NOERROR/NOWARNING: Suppress error messages
    int options = XML_PARSE_NOENT | XML_PARSE_NONET | XML_PARSE_NO_XXE |
                  XML_PARSE_RECOVER | XML_PARSE_NOERROR | XML_PARSE_NOWARNING;

    xmlParserCtxtPtr parser_ctxt = xmlCreatePushParserCtxt(
        &sax_handler, /* user data */ &ctx, NULL, 0, NULL);
    if (parser_ctxt == NULL) {
        mlog(dd_log_warning, "Failed to create XML push parser context");
        return result;
    }
    xmlCtxtUseOptions(parser_ctxt, options);
    ctx.parser_ctxt = parser_ctxt;

    int parse_result =
        xmlParseChunk(parser_ctxt, xml_data, (int)xml_len, 1 /* terminate */);

    // flush any remaining text
    _ctx_flush_text(&ctx);

    // xmlFreeParserCtxt does not free myDoc, so we must do it ourselves
    if (parser_ctxt->myDoc != NULL) {
        xmlFreeDoc(parser_ctxt->myDoc);
        parser_ctxt->myDoc = NULL;
    }
    xmlFreeParserCtxt(parser_ctxt);

    // clean up global error state (contains allocated strings for error
    // messages)
    // This may not get executed it case there is bailout during xmlParseChunk
    // (e.g. out of memory). In this case, the last error (global/thread-local)
    // will be pointing to freed memory. This is not a problem unless there's
    // an attempt to read the read the last error message.
    xmlResetLastError();

    if (parse_result != 0 && Z_TYPE(ctx.root) == IS_UNDEF) {
        mlog_g(dd_log_debug, "XML parsing failed with no partial data");
        return result;
    }

    if (parse_result != 0) {
        mlog_g(
            dd_log_debug, "XML parsing completed with errors (partial data)");
    } else {
        mlog_g(dd_log_debug, "Successfully parsed XML (full document)");
    }

    if (Z_TYPE(ctx.root) == IS_UNDEF) {
        return result;
    }

    // transfer ownership of the root
    result = ctx.root;
    ZVAL_UNDEF(&ctx.root);

    return result;
}

// libxml2 memory allocator wrappers

static void _dd_xml_free(void *mem)
{
    // efree doesn't accept nullptr
    if (mem != NULL) {
        mlog_g(dd_log_trace, "libxml2 free(%p)", mem);
        efree(mem);
    }
}

static void *_dd_xml_malloc(size_t size)
{
    void *ptr = emalloc(size);
    mlog_g(dd_log_trace, "libxml2 malloc(%zu) = %p", size, ptr);
    return ptr;
}

static void *_dd_xml_realloc(void *mem, size_t size)
{
    void *ptr;
    if (mem == NULL) {
        ptr = emalloc(size);
    } else {
        ptr = erealloc(mem, size);
    }
    mlog_g(dd_log_trace, "libxml2 realloc(%p, %zu) = %p", mem, size, ptr);
    return ptr;
}

static char *_dd_xml_strdup(const char *str)
{
    char *ptr = estrdup(str);
    mlog_g(dd_log_trace, "libxml2 strdup(\"%s\") = %p", str, (void *)ptr);
    return ptr;
}

// SAX callbacks

// SAX callback: resolveEntity - called to load external entities.
// With XML_PARSE_NO_XXE, libxml2 blocks external entity loading internally,
// so this callback is never invoked. Kept as a defensive fallback.
static xmlParserInputPtr _sax_resolve_entity(
    // NOLINTNEXTLINE(bugprone-easily-swappable-parameters)
    void *user_ctx, const xmlChar *publicId, const xmlChar *systemId)
{
    UNUSED(user_ctx);
    UNUSED(publicId);
    UNUSED(systemId);
    mlog(dd_log_warning,
        "Unexpected call to _sax_resolve_entity with public id '%s', system id "
        "'%s'",
        publicId, systemId);
    return NULL;
}

static void _output_entity_reference(_xml_parse_ctx *ctx, const xmlChar *name);

static xmlEntityPtr _sax_get_entity(void *user_ctx, const xmlChar *name)
{
    _xml_parse_ctx *ctx = (_xml_parse_ctx *)user_ctx;
    xmlEntityPtr entity = NULL;

    // Use libxml2's SAX2GetEntity which needs the parser context
    // xmlSAX2GetEntity is safe: it doesn't load/fetch/expand anything;
    // it merely looks up already parsed xmlEntity objects.
    if (ctx->parser_ctxt != NULL) {
        entity = xmlSAX2GetEntity(ctx->parser_ctxt, name);
    }
    if (entity == NULL) {
        // Fallback: try predefined entities (amp, lt, gt, quot, apos)
        entity = xmlGetPredefinedEntity(name);
    }

    if (entity == NULL) {
        // entity not found - output as literal reference.
        _output_entity_reference(ctx, name);
        return NULL;
    }

    // Only allow internal and predefined entities
    if (entity->etype == XML_EXTERNAL_GENERAL_PARSED_ENTITY ||
        entity->etype == XML_EXTERNAL_GENERAL_UNPARSED_ENTITY ||
        entity->etype == XML_EXTERNAL_PARAMETER_ENTITY) {
        // external entity blocked - output as literal reference
        _output_entity_reference(ctx, name);
        return NULL;
    }

    return entity;
}

// SAX callback: reference - called for unresolved entity references.
// With XML_PARSE_NOENT, libxml2 sets replaceEntities=1 and skips this callback
// for undeclared entities (see parser.c:7137)
static void _sax_reference(void *user_ctx, const xmlChar *name)
{
    UNUSED(user_ctx);
    mlog(dd_log_warning, "Unexpected call to _sax_reference with '%s'", name);
}

// Forward declarations for helpers used by SAX callbacks
static void _ctx_append_text(_xml_parse_ctx *ctx, const char *text, size_t len);
static size_t _ctx_stack_depth(_xml_parse_ctx *ctx);
static void _ctx_stack_push(_xml_parse_ctx *ctx, zval *container);
static zval *_ctx_stack_pop(_xml_parse_ctx *ctx);
static zval *nullable _ctx_stack_top(_xml_parse_ctx *ctx);

// SAX callback: start element
static void _sax_start_element(
    void *user_ctx, const xmlChar *tag_name, const xmlChar **atts)
{
    _xml_parse_ctx *ctx = (_xml_parse_ctx *)user_ctx;

    _ctx_flush_text(ctx);

    if (ctx->suppressed > 0) {
        ctx->suppressed++;
        return;
    }

    const size_t tag_len = xmlStrlen(tag_name);

    // Create element wrapper: {<tag>: [...]}
    zval elem_wrapper;
    array_init(&elem_wrapper);

    zval *elem_content_p;
    {
        // Create content array that will hold attributes, text, and children
        zval elem_content;
        array_init(&elem_content);
        // add it to the wrapper with tag name as key
        elem_content_p = zend_hash_str_add_new(Z_ARRVAL(elem_wrapper),
            (const char *)tag_name, tag_len, &elem_content);
        assert(elem_content_p != NULL);
    }

    // Add attributes with @ prefix if present
    if (atts != NULL) {
        zval attr_obj;
        array_init(&attr_obj);
        bool has_attrs = false;

        for (int i = 0; atts[i] != NULL; i += 2) {
            const xmlChar *attr_name = atts[i];
            const xmlChar *attr_value = atts[i + 1];
            if (attr_value == NULL) {
                attr_value = BAD_CAST "";
            }

            // Create key with @ prefix
            size_t attr_name_len = xmlStrlen(attr_name);
            char *prefixed_name = safe_emalloc(attr_name_len, 1, 2);
            prefixed_name[0] = '@';
            memcpy(prefixed_name + 1, attr_name, attr_name_len + 1);

            zval attr_val;
            ZVAL_STRINGL(
                &attr_val, (const char *)attr_value, xmlStrlen(attr_value));
            zend_hash_str_add_new(Z_ARRVAL(attr_obj), prefixed_name,
                attr_name_len + 1, &attr_val);
            efree(prefixed_name);
            has_attrs = true;
        }

        if (has_attrs) {
            zend_hash_next_index_insert(Z_ARRVAL_P(elem_content_p), &attr_obj);
        } else {
            zval_ptr_dtor(&attr_obj);
        }
    }

    // Add to current container or set as root
    if (Z_TYPE(ctx->root) == IS_UNDEF) {
        ctx->root = elem_wrapper;
        if (_ctx_stack_depth(ctx) < ctx->max_depth) {
            _ctx_stack_push(ctx, elem_content_p);
        } else {
            ctx->suppressed++;
        }
    } else {
        zval *container = _ctx_stack_top(ctx);
        if (container == NULL) {
            mlog(dd_log_warning, "Expected container, got NULL");
            zval_ptr_dtor(&elem_wrapper);
            return;
        }

        zval *inserted =
            zend_hash_next_index_insert(Z_ARRVAL_P(container), &elem_wrapper);
        UNUSED(inserted); // needed for non-debug builds
        assert(inserted != NULL);

        if (_ctx_stack_depth(ctx) < ctx->max_depth) {
            _ctx_stack_push(ctx, elem_content_p);
        } else {
            ctx->suppressed++;
        }
    }
}

// SAX callback: end element
static void _sax_end_element(void *user_ctx, const xmlChar *name)
{
    (void)name;
    _xml_parse_ctx *ctx = (_xml_parse_ctx *)user_ctx;

    _ctx_flush_text(ctx);

    if (ctx->suppressed > 0) {
        ctx->suppressed--;
        return;
    }

    _ctx_stack_pop(ctx);
}

// SAX callback: character data
static void _sax_characters(void *user_ctx, const xmlChar *ch, int len)
{
    _xml_parse_ctx *ctx = (_xml_parse_ctx *)user_ctx;

    if (ctx->suppressed > 0 || len <= 0) {
        return;
    }

    bool only_whitespace = true;
    for (int i = 0; i < len; i++) {
        unsigned char c = ch[i];
        if (c != ' ' && c != '\t' && c != '\n' && c != '\r') {
            only_whitespace = false;
            break;
        }
    }

    if (only_whitespace) {
        return;
    }

    _ctx_append_text(ctx, (const char *)ch, (size_t)len);
}

// SAX callback: CDATA block
static void _sax_cdata_block(void *user_ctx, const xmlChar *value, int len)
{
    // Treat CDATA the same as regular characters
    _sax_characters(user_ctx, value, len);
}

// Parser context functions

static void _ctx_init(_xml_parse_ctx *ctx, size_t max_depth)
{
    ZVAL_UNDEF(&ctx->root);
    _stack_init(&ctx->stack);
    ctx->max_depth = max_depth;
    ctx->suppressed = 0;
    ctx->text_buf = NULL;
    ctx->parser_ctxt = NULL;
}

static void _ctx_destroy(_xml_parse_ctx *ctx)
{
    if (Z_TYPE(ctx->root) != IS_UNDEF) {
        zval_ptr_dtor(&ctx->root);
        ZVAL_UNDEF(&ctx->root);
    }
    _stack_destroy(&ctx->stack);
    if (ctx->text_buf) {
        zend_string_release(ctx->text_buf);
        ctx->text_buf = NULL;
    }
}

// Cleanup function for __attribute__((cleanup)) on zend_string*
static void _zend_string_release_cleanup(zend_string **str)
{
    if (*str) {
        zend_string_release(*str);
    }
}

static size_t _ctx_stack_depth(_xml_parse_ctx *ctx) { return ctx->stack.size; }

static void _ctx_stack_push(_xml_parse_ctx *ctx, zval *container)
{
    _stack_push(&ctx->stack, container);
}

static zval *nullable _ctx_stack_pop(_xml_parse_ctx *ctx)
{
    return _stack_pop(&ctx->stack);
}

static zval *nullable _ctx_stack_top(_xml_parse_ctx *ctx)
{
    return _stack_top(&ctx->stack);
}

static void _ctx_flush_text(_xml_parse_ctx *ctx)
{
    __attribute__((cleanup(_zend_string_release_cleanup)))
    zend_string *text_buf = ctx->text_buf;

    ctx->text_buf = NULL;

    if (text_buf == NULL || ZSTR_LEN(text_buf) == 0) {
        return;
    }

    if (ctx->suppressed > 0) {
        return;
    }

    zval *container = _ctx_stack_top(ctx);
    if (container == NULL) {
        return;
    }
    if (Z_TYPE_P(container) != IS_ARRAY) {
        mlog(dd_log_warning, "Expected array container, got type %d",
            (int)Z_TYPE_P(container));
        return;
    }

    zval text_val;
    ZVAL_STR(&text_val, text_buf);
    zend_hash_next_index_insert(Z_ARRVAL_P(container), &text_val);
    // ownership transferred to the hash table; prevent cleanup from releasing
    text_buf = NULL;
}

static void _ctx_append_text(_xml_parse_ctx *ctx, const char *text, size_t len)
{
    if (len == 0) {
        return;
    }

    if (ctx->text_buf == NULL) {
        ctx->text_buf = zend_string_init(text, len, 0);
    } else {
        size_t old_len = ZSTR_LEN(ctx->text_buf);
        ctx->text_buf = zend_string_extend(ctx->text_buf, old_len + len, 0);
        memcpy(ZSTR_VAL(ctx->text_buf) + old_len, text, len);
        ZSTR_VAL(ctx->text_buf)[old_len + len] = '\0';
    }
}

// Helper to output an unresolved entity reference as literal text
static void _output_entity_reference(_xml_parse_ctx *ctx, const xmlChar *name)
{
    if (ctx->suppressed > 0) {
        return;
    }

    size_t name_len = strlen((const char *)name);
    size_t ref_len = name_len + 2; // & + name + ;

    char *ref = safe_emalloc(ref_len, 1, 0);
    ref[0] = '&';
    memcpy(ref + 1, name, name_len);
    ref[name_len + 1] = ';';

    _ctx_append_text(ctx, ref, ref_len);
    efree(ref);
}

// Stack implementation

static void _stack_init(_ptr_stack *s)
{
    // NOLINTNEXTLINE(bugprone-sizeof-expression)
    s->items = safe_emalloc(STACK_INITIAL_CAPACITY, sizeof(s->items[0]), 0);
    s->size = 0;
    s->capacity = STACK_INITIAL_CAPACITY;
}

static void _stack_destroy(_ptr_stack *s)
{
    if (s->items) {
        efree(s->items);
        s->items = NULL;
    }
    s->size = 0;
    s->capacity = 0;
}

static void _stack_push(_ptr_stack *s, zval *ptr)
{
    if (s->size >= s->capacity) {
        s->capacity *= 2;
        // NOLINTNEXTLINE(bugprone-sizeof-expression)
        s->items = safe_erealloc(s->items, s->capacity, sizeof(s->items[0]), 0);
    }
    s->items[s->size++] = ptr;
}

static zval *nullable _stack_pop(_ptr_stack *s)
{
    if (s->size == 0) {
        return NULL;
    }
    return s->items[--s->size];
}

static zval *nullable _stack_top(_ptr_stack *s)
{
    if (s->size == 0) {
        return NULL;
    }
    return s->items[s->size - 1];
}
