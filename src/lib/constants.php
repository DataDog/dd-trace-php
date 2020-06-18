<?php

namespace DDTrace;

const DEFAULT_URI_PART_NORMALIZE_REGEXES = [
    '/^\d+$/',
    '/^[0-9a-f]{8}-?[0-9a-f]{4}-?[1-5][0-9a-f]{3}-?[89ab][0-9a-f]{3}-?[0-9a-f]{12}$/',
    '/^[0-9a-f]{8,128}$/',
];
