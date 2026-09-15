package com.datadog.appsec.php.mock_grpc

import groovy.transform.CompileStatic
import io.grpc.Metadata
import io.grpc.MethodDescriptor
import io.grpc.Server
import io.grpc.ServerCall
import io.grpc.ServerCallHandler
import io.grpc.ServerInterceptor
import io.grpc.ServerInterceptors
import io.grpc.ServerServiceDefinition
import io.grpc.netty.shaded.io.grpc.netty.GrpcSslContexts
import io.grpc.netty.shaded.io.grpc.netty.NettyServerBuilder
import io.grpc.netty.shaded.io.netty.handler.ssl.util.SelfSignedCertificate
import io.grpc.stub.ServerCalls
import io.grpc.stub.StreamObserver
import org.testcontainers.lifecycle.Startable

import java.nio.charset.StandardCharsets
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.ConcurrentMap
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger

@CompileStatic
class MockGrpcServer implements Startable {
    private static final String SERVICE_NAME = 'appsec.CrashProbe'
    private static final String METHOD_NAME = 'Check'
    private static final Metadata.Key<String> PLUGIN_METADATA =
            Metadata.Key.of(
                    'x-dd-grpc-plugin',
                    Metadata.ASCII_STRING_MARSHALLER)
    private static final MethodDescriptor.Marshaller<byte[]> BYTE_MARSHALLER =
            new MethodDescriptor.Marshaller<byte[]>() {
                @Override
                InputStream stream(byte[] value) {
                    new ByteArrayInputStream(value)
                }

                @Override
                byte[] parse(InputStream stream) {
                    stream.readAllBytes()
                }
            }
    private static final MethodDescriptor<byte[], byte[]> METHOD =
            MethodDescriptor.<byte[], byte[]>newBuilder()
                    .setType(MethodDescriptor.MethodType.UNARY)
                    .setFullMethodName(
                            MethodDescriptor.generateFullMethodName(
                                    SERVICE_NAME, METHOD_NAME))
                    .setRequestMarshaller(BYTE_MARSHALLER)
                    .setResponseMarshaller(BYTE_MARSHALLER)
                    .build()

    private final AtomicInteger requestCounter = new AtomicInteger()
    private final AtomicInteger pluginMetadataCounter = new AtomicInteger()
    private final ConcurrentMap<String, CountDownLatch> expectedRequests =
            new ConcurrentHashMap<>()
    private SelfSignedCertificate certificate
    private Server server

    @Override
    void start() {
        certificate = new SelfSignedCertificate('grpc.test')
        if (!certificate.certificate().setReadable(true, false)) {
            throw new IOException('Could not make the gRPC certificate readable')
        }
        ServerServiceDefinition service = ServerServiceDefinition
                .builder(SERVICE_NAME)
                .addMethod(METHOD, ServerCalls.asyncUnaryCall(
                        new ServerCalls.UnaryMethod<byte[], byte[]>() {
                            @Override
                            void invoke(byte[] request,
                                        StreamObserver<byte[]> observer) {
                                requestCounter.incrementAndGet()
                                String payload = new String(
                                        request, StandardCharsets.UTF_8)
                                int newline = payload.indexOf('\n')
                                String requestLine = newline < 0 ?
                                        payload : payload.substring(0, newline)
                                if (requestLine.startsWith('request:')) {
                                    expectedRequests.get(requestLine.substring(
                                            'request:'.length()))?.countDown()
                                }
                                String response = payload.replaceFirst(
                                        '^request:', 'response:')
                                observer.onNext(response.getBytes(
                                        StandardCharsets.UTF_8))
                                observer.onCompleted()
                            }
                        }))
                .build()
        ServerInterceptor metadataCounter = new ServerInterceptor() {
            @Override
            def <ReqT, RespT> ServerCall.Listener<ReqT> interceptCall(
                    ServerCall<ReqT, RespT> call,
                    Metadata headers,
                    ServerCallHandler<ReqT, RespT> next) {
                if (headers.get(PLUGIN_METADATA) != null) {
                    pluginMetadataCounter.incrementAndGet()
                }
                next.startCall(call, headers)
            }
        }

        server = NettyServerBuilder.forPort(0)
                .sslContext(GrpcSslContexts.forServer(
                        certificate.certificate(), certificate.privateKey())
                        .build())
                .addService(ServerInterceptors.intercept(
                        service, metadataCounter))
                .build()
                .start()
    }

    int getPort() {
        server.port
    }

    File getCertificateFile() {
        certificate.certificate()
    }

    int getRequestCount() {
        requestCounter.get()
    }

    int getRequestsWithPluginMetadata() {
        pluginMetadataCounter.get()
    }

    void expectRequest(String label) {
        CountDownLatch previous = expectedRequests.putIfAbsent(
                label, new CountDownLatch(1))
        if (previous != null) {
            throw new IllegalStateException(
                    "An expectation already exists for $label")
        }
    }

    boolean awaitExpectedRequest(String label, long timeoutMillis) {
        CountDownLatch latch = expectedRequests.get(label)
        if (latch == null) {
            throw new IllegalStateException("No expectation exists for $label")
        }
        try {
            return latch.await(timeoutMillis, TimeUnit.MILLISECONDS)
        } finally {
            expectedRequests.remove(label, latch)
        }
    }

    @Override
    void stop() {
        server?.shutdownNow()
        server?.awaitTermination()
        server = null
        expectedRequests.clear()
        certificate?.delete()
        certificate = null
    }
}
