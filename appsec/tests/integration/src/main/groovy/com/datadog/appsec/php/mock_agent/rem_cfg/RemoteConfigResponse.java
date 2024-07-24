package com.datadog.appsec.php.mock_agent.rem_cfg;

import com.fasterxml.jackson.annotation.JsonProperty;
import com.fasterxml.jackson.core.JsonParser;
import com.fasterxml.jackson.databind.DeserializationContext;
import com.fasterxml.jackson.databind.JsonDeserializer;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.annotation.JsonDeserialize;
import java.io.IOException;
import java.lang.reflect.UndeclaredThrowableException;
import java.math.BigInteger;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.time.Instant;
import java.util.Base64;
import java.util.Collections;
import java.util.List;
import java.util.Map;

public class RemoteConfigResponse {
    @JsonProperty("client_configs")
    public List<String> clientConfigs;

    @JsonDeserialize(using = TargetsDeserializer.class)
    private Targets targets;

    @JsonProperty("target_files")
    public List<TargetFile> targetFiles;

    public Targets.ConfigTarget getTarget(String configKey) {
        return this.targets.targetsSigned.targets.get(configKey);
    }

    public String getTargetsSignature(String keyId) {
        for (Targets.Signature signature : this.targets.signatures) {
            if (keyId.equals(signature.keyId)) {
                return signature.signature;
            }
        }

        throw new IntegrityCheckException("Missing signature for key " + keyId);
    }

    public Targets.TargetsSigned getTargetsSigned() {
        return this.targets.targetsSigned;
    }

    public byte[] getFileContents(String configKey) {

        if (targetFiles == null) {
            throw new MissingContentException("No content for " + configKey);
        }

        try {
            for (TargetFile targetFile : this.targetFiles) {
                if (!configKey.equals(targetFile.path)) {
                    continue;
                }

                Targets.ConfigTarget configTarget = getTarget(configKey);
                String hashStr;
                if (configTarget == null
                        || configTarget.hashes == null
                        || (hashStr = configTarget.hashes.get("sha256")) == null) {
                    throw new IntegrityCheckException("No sha256 hash present for " + configKey);
                }
                BigInteger expectedHash = new BigInteger(hashStr, 16);

                String raw = targetFile.raw;
                byte[] decode = Base64.getDecoder().decode(raw);
                BigInteger gottenHash = sha256(decode);
                if (!expectedHash.equals(gottenHash)) {
                    throw new IntegrityCheckException(
                            "File "
                                    + configKey
                                    + " does not "
                                    + "have the expected sha256 hash: Expected "
                                    + expectedHash.toString(16)
                                    + ", but got "
                                    + gottenHash.toString(16));
                }
                if (decode.length != configTarget.length) {
                    throw new IntegrityCheckException(
                            "File "
                                    + configKey
                                    + " does not "
                                    + "have the expected length: Expected "
                                    + configTarget.length
                                    + ", but got "
                                    + decode.length);
                }

                return decode;
            }
        } catch (IntegrityCheckException e) {
            throw e;
        } catch (Exception exception) {
            throw new IntegrityCheckException(
                    "Could not get file contents from remote config, file " + configKey, exception);
        }

        throw new MissingContentException("No content for " + configKey);
    }

    private static BigInteger sha256(byte[] bytes) {
        try {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            byte[] hash = digest.digest(bytes);
            return new BigInteger(1, hash);
        } catch (NoSuchAlgorithmException e) {
            throw new UndeclaredThrowableException(e);
        }
    }

    public List<String> getClientConfigs() {
        return this.clientConfigs != null ? this.clientConfigs : Collections.emptyList();
    }

    public static class Targets {
        public List<Signature> signatures;

        @JsonProperty("signed")
        public TargetsSigned targetsSigned;

        public static class Signature {
            @JsonProperty("keyid")
            public String keyId;

            @JsonProperty("sig")
            public String signature;
        }

        public static class TargetsSigned {
            @JsonProperty("_type")
            public String type;

            public TargetsCustom custom;
            public Instant expires;

            @JsonProperty("spec_version")
            public String specVersion;

            public long version;
            public Map<String, ConfigTarget> targets;

            public static class TargetsCustom {
                @JsonProperty("opaque_backend_state")
                public String opaqueBackendState;
            }
        }

        public static class ConfigTarget {
            public ConfigTargetCustom custom;
            public Map<String, String> hashes;
            public long length;

            public static class ConfigTargetCustom {
                @JsonProperty("v")
                public long version;
            }
        }
    }

    public static class TargetFile {
        public String path;
        public String raw;
    }

    public static class TargetsDeserializer extends JsonDeserializer<Targets> {
        @Override
        public Targets deserialize(JsonParser jsonParser, DeserializationContext deserializationContext)
                throws IOException {
            JsonNode node = jsonParser.getCodec().readTree(jsonParser);
            String targetsJsonBase64 = node.asText();
            byte[] targetsJsonDecoded =
                    Base64.getDecoder().decode(targetsJsonBase64.getBytes(StandardCharsets.ISO_8859_1));

            JsonParser defParser = jsonParser.getCodec().getFactory().createParser(targetsJsonDecoded);

            JsonDeserializer<?> defaultDeserializer = deserializationContext.findRootValueDeserializer(
                    deserializationContext.constructType(Targets.class));
            return (Targets) defaultDeserializer.deserialize(defParser, deserializationContext);
        }
    }
}
