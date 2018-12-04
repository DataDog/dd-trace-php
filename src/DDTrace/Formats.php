<?php

namespace DDTrace\Formats;

use OpenTracing\Formats as OTFormats;

/**
 * {@inheritdoc}
 */
const BINARY = OTFormats\BINARY;

/**
 * {@inheritdoc}
 */
const TEXT_MAP = OTFormats\TEXT_MAP;

/**
 * {@inheritdoc}
 */
const HTTP_HEADERS = OTFormats\HTTP_HEADERS;

/**
 * A propagator that handles curl style http headers arrays.
 */
const CURL_HTTP_HEADERS = 'curl_http_headers';
