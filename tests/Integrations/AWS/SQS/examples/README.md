# How to run tests

Run the message processor

```
DD_TRACE_CLI_ENABLED=1 \
    DD_TRACE_GENERATE_ROOT_SPAN=0 \
    DD_AGENT_HOST=agent \
    php \
    -d ddtrace.request_init_hook=/home/circleci/app/bridge/dd_wrap_autoloader.php \
    sample_processor.php
```

Send a batch of 10 messages sequentially

```
php sample_sender.php -n10
```

Send many batch of messages in parallel

```
for i in {1..10}; do php sample_sender.php  -n10 & done
```
