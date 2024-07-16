package com.datadog.appsec.php.mock_agent.rem_cfg;

import com.fasterxml.jackson.annotation.JsonProperty;
import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collection;
import java.util.List;
import java.util.Map;
import java.util.StringJoiner;
import java.util.stream.Collectors;

public class RemoteConfigRequest {

    public static RemoteConfigRequest newRequest(
            String clientId,
            String runtimeId,
            String tracerVersion,
            Collection<String> productNames,
            String serviceName,
            List<String> extraServices,
            String serviceEnv,
            String serviceVersion,
            List<String> tags,
            ClientInfo.ClientState clientState,
            Collection<CachedTargetFile> cachedTargetFiles,
            long capabilities) {

        ClientInfo.TracerInfo tracerInfo =
                new RemoteConfigRequest.ClientInfo.TracerInfo();
        tracerInfo.runtimeId = runtimeId;
        tracerInfo.tracerVersion = tracerVersion;
        tracerInfo.serviceName = serviceName;
        tracerInfo.extraServices = extraServices;
        tracerInfo.serviceEnv = serviceEnv;
        tracerInfo.serviceVersion = serviceVersion;
        tracerInfo.tags = tags;

        ClientInfo clientInfo =
                new RemoteConfigRequest.ClientInfo(
                        clientState, clientId, productNames, tracerInfo, capabilities);

        RemoteConfigRequest rcr = new RemoteConfigRequest();
        rcr.client = clientInfo;
        rcr.cachedTargetFiles = cachedTargetFiles;

        return rcr;
    }

    private ClientInfo client;

    @JsonProperty("cached_target_files")
    private Collection<CachedTargetFile> cachedTargetFiles;

    public ClientInfo getClient() {
        return this.client;
    }

    /** Stores client information for Remote Configuration */
    public static class ClientInfo {
        @JsonProperty("state")
        public ClientState clientState;

        public String id;
        public Collection<String> products;

        @JsonProperty("client_tracer")
        public TracerInfo tracerInfo;

        @JsonProperty("client_agent")
        public AgentInfo agentInfo = null; // MUST NOT be set

        @JsonProperty("is_tracer")
        public boolean isTracer = true;

        @JsonProperty("is_agent")
        public Boolean isAgent = null; // MUST NOT be set;

        public byte[] capabilities;

        public ClientInfo() {}
        public ClientInfo(
                ClientState clientState,
                String id,
                Collection<String> productNames,
                TracerInfo tracerInfo,
                final long capabilities) {
            this.clientState = clientState;
            this.id = id;
            this.products = productNames;
            this.tracerInfo = tracerInfo;

            // Big-endian encoding of the `long` capabilities, stripping any trailing zero bytes
            // (except the first one)
            final int size = Math.max(1, Long.BYTES - Long.numberOfLeadingZeros(capabilities) / 8);
            this.capabilities = new byte[size];
            for (int i = size - 1; i >= 0; i--) {
                this.capabilities[size - i - 1] = (byte) (capabilities >>> (i * 8));
            }
        }

        public TracerInfo getTracerInfo() {
            return this.tracerInfo;
        }

        public static class ClientState {
            @JsonProperty("root_version")
            public long rootVersion = 1L;

            @JsonProperty("targets_version")
            public long targetsVersion;

            @JsonProperty("config_states")
            public List<ConfigState> configStates = new ArrayList<>();

            @JsonProperty("has_error")
            public boolean hasError;

            public String error;

            @JsonProperty("backend_client_state")
            public String backendClientState;

            public void setState(
                    long targetsVersion,
                    List<ConfigState> configStates,
                    String error,
                    String backendClientState) {
                this.targetsVersion = targetsVersion;
                this.configStates = configStates;
                this.error = error;
                this.hasError = error != null && !error.isEmpty();
                this.backendClientState = backendClientState;
            }

            public static class ConfigState {
                public static final int APPLY_STATE_ACKNOWLEDGED = 2;
                public static final int APPLY_STATE_ERROR = 3;

                private String id;
                private long version;
                public String product;

                @JsonProperty("apply_state")
                public int applyState;

                @JsonProperty("apply_error")
                public String applyError;

                public void setState(String id, long version, String product, String error) {
                    this.id = id;
                    this.version = version;
                    this.product = product;
                    this.applyState = error == null ? APPLY_STATE_ACKNOWLEDGED : APPLY_STATE_ERROR;
                    this.applyError = error;
                }

                @Override
                public String toString() {
                    return new StringJoiner(", ", ConfigState.class.getSimpleName() + "[", "]")
                            .add("id='" + id + "'")
                            .add("version=" + version)
                            .add("product='" + product + "'")
                            .add("applyState=" + applyState)
                            .add("applyError='" + applyError + "'")
                            .toString();
                }
            }

            @Override
            public String toString() {
                return new StringJoiner(", ", ClientState.class.getSimpleName() + "[", "]")
                        .add("rootVersion=" + rootVersion)
                        .add("targetsVersion=" + targetsVersion)
                        .add("configStates=" + configStates)
                        .add("hasError=" + hasError)
                        .add("error='" + error + "'")
                        .add("backendClientState='" + backendClientState + "'")
                        .toString();
            }
        }

        public static class TracerInfo {
            @JsonProperty("runtime_id")
            public String runtimeId;

            public String language = "java";

            public List<String> tags;

            @JsonProperty("tracer_version")
            public String tracerVersion;

            @JsonProperty("service")
            public String serviceName;

            @JsonProperty("extra_services")
            public List<String> extraServices;

            @JsonProperty("env")
            public String serviceEnv;

            @JsonProperty("app_version")
            public String serviceVersion;

            @Override
            public String toString() {
                return new StringJoiner(", ", TracerInfo.class.getSimpleName() + "[", "]")
                        .add("runtimeId='" + runtimeId + "'")
                        .add("language='" + language + "'")
                        .add("tags=" + tags)
                        .add("tracerVersion='" + tracerVersion + "'")
                        .add("serviceName='" + serviceName + "'")
                        .add("extraServices=" + extraServices)
                        .add("serviceEnv='" + serviceEnv + "'")
                        .add("serviceVersion='" + serviceVersion + "'")
                        .toString();
            }
        }

        private class AgentInfo {
            String name;
            String version;

            @Override
            public String toString() {
                return new StringJoiner(", ", AgentInfo.class.getSimpleName() + "[", "]")
                        .add("name='" + name + "'")
                        .add("version='" + version + "'")
                        .toString();
            }
        }

        @Override
        public String toString() {
            return new StringJoiner(", ", ClientInfo.class.getSimpleName() + "[", "]")
                    .add("clientState=" + clientState)
                    .add("id='" + id + "'")
                    .add("products=" + products)
                    .add("tracerInfo=" + tracerInfo)
                    .add("agentInfo=" + agentInfo)
                    .add("isTracer=" + isTracer)
                    .add("isAgent=" + isAgent)
                    .add("capabilities=" + Arrays.toString(capabilities))
                    .toString();
        }
    }

    public static class CachedTargetFile {
        public String path;
        public long length;
        public List<TargetFileHash> hashes;

        public CachedTargetFile() {}
        public CachedTargetFile(
                String path, long length, Map<String /*algo*/, String /*digest*/> hashes) {
            this.path = path;
            this.length = length;
            List<TargetFileHash> hashesList =
                    hashes.entrySet().stream()
                            .map(e -> new TargetFileHash(e.getKey(), e.getValue()))
                            .collect(Collectors.toList());
            this.hashes = hashesList;
        }

        public boolean hashesMatch(Map<String /*algo*/, String /*digest*/> hashesMap) {
            if (this.hashes == null) {
                return false;
            }

            if (hashesMap.size() != this.hashes.size()) {
                return false;
            }

            for (TargetFileHash tfh : hashes) {
                String digest = hashesMap.get(tfh.algorithm);
                if (!digest.equals(tfh.hash)) {
                    return false;
                }
            }

            return true;
        }

        public class TargetFileHash {
            String algorithm;
            String hash;

            TargetFileHash() {}
            TargetFileHash(String algorithm, String hash) {
                this.algorithm = algorithm;
                this.hash = hash;
            }

            @Override
            public String toString() {
                return new StringJoiner(", ", TargetFileHash.class.getSimpleName() + "[", "]")
                        .add("algorithm='" + algorithm + "'")
                        .add("hash='" + hash + "'")
                        .toString();
            }
        }

        @Override
        public String toString() {
            return new StringJoiner(", ", CachedTargetFile.class.getSimpleName() + "[", "]")
                    .add("path='" + path + "'")
                    .add("length=" + length)
                    .add("hashes=" + hashes)
                    .toString();
        }
    }

    @Override
    public String toString() {
        return new StringJoiner(", ", RemoteConfigRequest.class.getSimpleName() + "[", "]")
                .add("client=" + client)
                .add("cachedTargetFiles=" + cachedTargetFiles)
                .toString();
    }
}
