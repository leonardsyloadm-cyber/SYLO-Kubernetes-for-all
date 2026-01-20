package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;

public class KyloBigInt extends KyloType {

    @Override
    public int getFixedSize() {
        return 8; // 8 bytes
    }

    @Override
    public boolean isVariableLength() {
        return false;
    }

    @Override
    public void validate(Object value) {
        if (!(value instanceof Long)) {
            throw new IllegalArgumentException("Expected Long, got " + value.getClass().getName());
        }
    }

    @Override
    public byte[] serialize(Object value) {
        ByteBuffer buffer = ByteBuffer.allocate(8);
        buffer.putLong((Long) value);
        return buffer.array();
    }

    @Override
    public Object deserialize(byte[] data) {
        return ByteBuffer.wrap(data).getLong();
    }

    @Override
    public Object deserialize(ByteBuffer buffer) {
        return buffer.getLong();
    }

    @Override
    public void serialize(Object value, ByteBuffer buffer) {
        buffer.putLong((Long) value);
    }
}
