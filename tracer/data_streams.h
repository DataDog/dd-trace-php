#ifndef DD_DATA_STREAMS_H
#define DD_DATA_STREAMS_H

#include <php.h>

/**
 * Data Streams Monitoring checkpoints, for integrations to set when a message enters or leaves
 * the service. The pathway itself - hashing, the request's current pathway, and recording the
 * stats point for the sidecar - lives in components-rs/data_streams.rs.
 */

// The header (or other carrier key) the pathway context travels in.
#define DDTRACE_DSM_CONTEXT_KEY "dd-pathway-ctx-base64"

/**
 * Set a checkpoint with `edge_tags` (an array of "key:value" strings) and return the pathway
 * context to send on with the message, or NULL when Data Streams Monitoring is disabled.
 * `carrier` is the context the message arrived with, or NULL.
 */
zend_string *ddtrace_dsm_set_checkpoint(zend_array *edge_tags, zend_string *carrier);

// Forget the request's pathway.
void ddtrace_dsm_rshutdown(void);

#endif  // DD_DATA_STREAMS_H
