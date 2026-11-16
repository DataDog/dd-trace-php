package com.datadog.appsec.php.mock_agent

import com.google.common.collect.Lists
import com.google.common.collect.Maps
import org.msgpack.core.MessageFormat
import org.msgpack.core.MessageUnpacker
import org.msgpack.value.ValueType

class MsgpackHelper {

    static Object unpackSingle(MessageUnpacker unpacker) {
        MessageFormat format = unpacker.nextFormat
        ValueType type = format.valueType
        switch (type) {
            case ValueType.NIL:
                unpacker.unpackNil()
                return null
            case ValueType.BOOLEAN:
                return unpacker.unpackBoolean();
            case ValueType.INTEGER:
                switch (format) {
                    case MessageFormat.UINT64:
                        return unpacker.unpackBigInteger()
                    case MessageFormat.INT64:
                    case MessageFormat.UINT32:
                        return unpacker.unpackLong()
                    default:
                        return unpacker.unpackInt()
                }
            case ValueType.FLOAT:
                return unpacker.unpackDouble()
            case ValueType.STRING:
                return unpacker.unpackString()
            case ValueType.BINARY: {
                int length = unpacker.unpackBinaryHeader()
                byte[] data = new byte[length]
                unpacker.readPayload(data)
                return data
            }
            case ValueType.ARRAY: {
                int length = unpacker.unpackArrayHeader()
                def ret = Lists.newArrayListWithCapacity(length)
                for (int i = 0; i < length; i++) {
                    ret << unpackSingle(unpacker)
                }
                return ret
            }
            case ValueType.MAP: {
                int length = unpacker.unpackMapHeader()
                def ret = Maps.newHashMapWithExpectedSize(length)
                for (int i = 0; i < length; i++) {
                    def key = unpackSingle(unpacker)
                    def value = unpackSingle(unpacker)
                    ret[key] = value
                }
                return ret
            }
            case ValueType.EXTENSION:
                return null
        }
    }
}
