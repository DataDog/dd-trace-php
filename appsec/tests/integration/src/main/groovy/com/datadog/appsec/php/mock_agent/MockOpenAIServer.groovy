package com.datadog.appsec.php.mock_agent

import groovy.json.JsonSlurper
import groovy.transform.CompileStatic
import groovy.util.logging.Slf4j
import io.javalin.Javalin
import io.javalin.http.Context
import org.testcontainers.lifecycle.Startable

@Slf4j
@CompileStatic
class MockOpenAIServer implements Startable {
    private static final int PORT = 8089
    Javalin httpServer

    @Override
    void start() {
        this.httpServer = Javalin.create(config -> {
            config.showJavalinBanner = false
        })

        // Support both /path and /v1/path for OpenAI client compatibility
        this.httpServer.post('/chat/completions', this.&handleChatCompletions)
        this.httpServer.post('/v1/chat/completions', this.&handleChatCompletions)
        this.httpServer.post('/completions', this.&handleCompletions)
        this.httpServer.post('/v1/completions', this.&handleCompletions)
        this.httpServer.post('/responses', this.&handleResponses)
        this.httpServer.post('/v1/responses', this.&handleResponses)

        this.httpServer.error(404, ctx -> {
            log.info("Unmatched OpenAI mock request: ${ctx.method()} ${ctx.path()}")
            ctx.status(404).json(['error': 'Not Found'])
        })
        this.httpServer.error(405, ctx -> {
            ctx.status(405).json(['error': 'Method Not Allowed'])
        })

        this.httpServer.start(PORT)
    }

    int getPort() {
        PORT
    }

    @Override
    void stop() {
        if (httpServer != null) {
            this.httpServer.stop()
            this.httpServer = null
        }
    }

    private static Map<String, ?> parseBody(Context ctx) {
        String raw = ctx.body()
        if (raw == null || raw.isEmpty()) {
            return [:]
        }
        try {
            def decoded = new JsonSlurper().parseText(raw)
            return decoded instanceof Map ? (Map<String, ?>) decoded : [:]
        } catch (Exception e) {
            return [:]
        }
    }

    private static Map<String, ?> fakeUsage() {
        [
                'prompt_tokens'  : 1,
                'completion_tokens': 2,
                'total_tokens'   : 3,
        ]
    }

    private void handleChatCompletions(Context ctx) {
        Map<String, ?> body = parseBody(ctx)
        String model = (body['model'] as String) ?: 'gpt-4.1'
        ctx.json([
                'id'     : 'chatcmpl-fake-internal',
                'object' : 'chat.completion',
                'created': (long)(System.currentTimeMillis() / 1000),
                'model'  : model,
                'choices': [
                        [
                                'index'         : 0,
                                'message'       : [
                                        'role'   : 'assistant',
                                        'content': 'Fake response from internal_server mock.',
                                ],
                                'finish_reason': 'stop',
                        ],
                ],
                'usage'  : fakeUsage(),
        ])
    }

    private void handleCompletions(Context ctx) {
        Map<String, ?> body = parseBody(ctx)
        String model = (body['model'] as String) ?: 'text-davinci-003'
        ctx.json([
                'id'     : 'cmpl-fake-internal',
                'object' : 'text_completion',
                'created': (long)(System.currentTimeMillis() / 1000),
                'model'  : model,
                'choices': [
                        [
                                'text'          : 'Fake completion from internal_server mock.',
                                'index'         : 0,
                                'finish_reason': 'stop',
                                'logprobs'     : null,
                        ],
                ],
                'usage'  : fakeUsage(),
        ])
    }

    private void handleResponses(Context ctx) {
        Map<String, ?> body = parseBody(ctx)
        String model = (body['model'] as String) ?: 'gpt-4.1'
        ctx.json([
                'id'                  : 'resp-fake-internal',
                'object'              : 'response',
                'created_at'          : (long)(System.currentTimeMillis() / 1000),
                'status'              : 'completed',
                'model'               : model,
                'output'              : [
                        [
                                'type'   : 'message',
                                'id'     : 'msg-fake-internal',
                                'role'   : 'assistant',
                                'status' : 'completed',
                                'content': [
                                        [
                                                'type'       : 'output_text',
                                                'text'       : 'Fake response from internal_server mock.',
                                                'annotations': [],
                                        ],
                                ],
                        ],
                ],
                'output_text'         : 'Fake response from internal_server mock.',
                'parallel_tool_calls' : false,
                'tool_choice'         : 'none',
                'tools'               : [],
                'store'               : true,
                'usage'               : [
                        'input_tokens'          : 1,
                        'input_tokens_details'  : ['cached_tokens': 0],
                        'output_tokens'         : 2,
                        'output_tokens_details' : ['reasoning_tokens': 0],
                        'total_tokens'          : 3,
                ],
        ])
    }
}
