#ifndef DD_DATA_STREAMS_H
#define DD_DATA_STREAMS_H

#include <php.h>
#include <stdbool.h>

/**
 * Data Streams Monitoring checkpoints, for integrations to set when a message enters or leaves
 * the service. The pathway itself - hashing, the request's current pathway, and recording the
 * stats point for the sidecar - lives in components-rs/data_streams.rs.
 */

// The header (or other carrier key) the pathway context travels in.
#define DDTRACE_DSM_CONTEXT_KEY "dd-pathway-ctx-base64"

/**
 * Whether Data Streams Monitoring is on: DD_DATA_STREAMS_ENABLED is set, and the sidecar, which
 * aggregates the stats points and sends them, is enabled for this process. DD_DATA_STREAMS_ENABLED
 * starts the sidecar, so the second part only fails when the sidecar could not be set up, or when
 * DSM was enabled for the current request alone.
 */
bool ddtrace_dsm_enabled(void);

/**
 * Set a checkpoint with `edge_tags` (an array of "key:value" strings) and return the pathway
 * context to send on with the message, or NULL when Data Streams Monitoring is off.
 * `carrier` is the context the message arrived with, or NULL. `payload_size` is the message's size
 * in bytes, or 0 if unknown.
 */
zend_string *ddtrace_dsm_set_checkpoint(zend_array *edge_tags, zend_string *carrier, zend_long payload_size);

/**
 * Record `offset` for the backlog named by `tags` (an array of "key:value" strings in any order,
 * with keys among topic, type, kafka_cluster_id, consumer_group and partition), e.g. a Kafka
 * partition's produce offset. Does nothing when Data Streams Monitoring is disabled.
 */
void ddtrace_dsm_track_backlog(zend_array *tags, zend_long offset);

// Forget the request's pathway.
void ddtrace_dsm_rshutdown(void);

#endif  // DD_DATA_STREAMS_H
