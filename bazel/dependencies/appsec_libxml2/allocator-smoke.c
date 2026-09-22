#include <libxml/parser.h>
#include <libxml/xmlmemory.h>
#include <stdlib.h>
#include <string.h>

static unsigned long allocations;
static unsigned long frees;
static void *counted_malloc(size_t n) { void *p = malloc(n); if (p) allocations++; return p; }
static void counted_free(void *p) { if (p) frees++; free(p); }
static void *counted_realloc(void *p, size_t n) { void *q = realloc(p, n); if (!p && q) allocations++; return q; }
static char *counted_strdup(const char *s) { size_t n = strlen(s) + 1; char *p = counted_malloc(n); return p ? memcpy(p, s, n) : 0; }

#if APPSEC_EXPECT_THREADS
# ifndef LIBXML_THREAD_ENABLED
#  error "ZTS PHP SDK requires LIBXML_THREAD_ENABLED"
# endif
#else
# ifdef LIBXML_THREAD_ENABLED
#  error "NTS PHP SDK must not enable LIBXML_THREAD_ENABLED"
# endif
#endif

/* The production version script exports these two AppSec entry points. */
void get_module(void) {}
void dd_appsec_maybe_enable_helper(void) {}

int appsec_libxml2_allocator_smoke(void) {
    xmlParserCtxtPtr parser;
    xmlDocPtr doc;
    if (xmlMemSetup(counted_free, counted_malloc, counted_realloc, counted_strdup) != 0)
        return 1;
    parser = xmlCreatePushParserCtxt(0, 0, "<root>", sizeof("<root>") - 1, "memory.xml");
    if (!parser || xmlParseChunk(parser, "<child/></root>", sizeof("<child/></root>") - 1, 1) != 0)
        return 2;
    doc = parser->myDoc;
    parser->myDoc = 0;
    xmlFreeParserCtxt(parser);
    if (!doc || !xmlDocGetRootElement(doc) || strcmp((const char *)xmlDocGetRootElement(doc)->name, "root") || !xmlDocGetRootElement(doc)->children || strcmp((const char *)xmlDocGetRootElement(doc)->children->name, "child"))
        return 3;
    xmlFreeDoc(doc);
    xmlCleanupParser();
    if (xmlHasFeature(XML_WITH_THREAD) != APPSEC_EXPECT_THREADS)
        return 4;
    return allocations > 0 && allocations == frees ? 0 : 5;
}

int main(void) { return appsec_libxml2_allocator_smoke(); }
