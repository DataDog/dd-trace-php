<?php

namespace DDTrace\Tags;

const ENV = 'env';
const SPAN_TYPE = 'span.type';
const SERVICE_NAME = 'service.name';
const PID = 'system.pid';
const RESOURCE_NAME = 'resource.name';
const DB_STATEMENT = 'sql.query';
const ERROR = 'error';
const ERROR_MSG = 'error.msg'; // string representing the error message
const ERROR_TYPE = 'error.type'; // string representing the type of the error
const ERROR_STACK = 'error.stack'; // human readable version of the stack
const HTTP_METHOD = 'http.method';
const HTTP_STATUS_CODE = 'http.status_code';
const HTTP_URL = 'http.url';
const LOG_EVENT = 'event';
const LOG_ERROR = 'error';
const LOG_ERROR_OBJECT = 'error.object';
const LOG_MESSAGE = 'message';
const LOG_STACK = 'stack';
const TARGET_HOST = 'out.host';
const TARGET_PORT = 'out.port';
const BYTES_OUT = 'net.out.bytes';
