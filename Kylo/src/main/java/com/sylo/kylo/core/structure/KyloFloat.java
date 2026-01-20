package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;

public class KyloFloat extends KyloType {

    @Override
    public int getFixedSize() {
        return 4;
    }

    @Override
    public boolean isVariableLength() {
        return false;
    }

    @Override
    public void validate(Object value) {
        if (!(value instanceof Float)) {
            throw new IllegalArgumentException("Expected Float, got " + value.getClass().getName());
        }
    }

    @Override
    public byte[] serialize(Object value) {
        ByteBuffer buffer = ByteBuffer.allocate(4);
        buffer.putFloat((Float) value);
        return buffer.array();
    }

    @Override
    public Object deserialize(byte[] data) {
        return ByteBuffer.wrap(data).getFloat();
    }

    @Override
    public Object deserialize(ByteBuffer buffer) {
        return buffer.getFloat();
    }

    @Override
    public void serialize(Object value, ByteBuffer buffer) {
        buffer.putFloat((Float) value);
    }
}
