package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;

public class KyloInt extends KyloType {

    @Override
    public int getFixedSize() {
        return 4; // 4 bytes
    }

    @Override
    public boolean isVariableLength() {
        return false;
    }

    @Override
    public void validate(Object value) {
        if (!(value instanceof Integer)) {
            throw new IllegalArgumentException("Expected Integer, got " + value.getClass().getName());
        }
    }

    @Override
    public byte[] serialize(Object value) {
        ByteBuffer buffer = ByteBuffer.allocate(4);
        buffer.putInt((Integer) value);
        return buffer.array();
    }

    @Override
    public Object deserialize(byte[] data) {
        return ByteBuffer.wrap(data).getInt();
    }

    @Override
    public Object deserialize(ByteBuffer buffer) {
        return buffer.getInt();
    }

    @Override
    public void serialize(Object value, ByteBuffer buffer) {
        buffer.putInt((Integer) value);
    }

    @Override
    public String toString() {
        return "INT";
    }
}
