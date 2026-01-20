package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;

public class KyloDouble extends KyloType {

    @Override
    public int getFixedSize() {
        return 8;
    }

    @Override
    public boolean isVariableLength() {
        return false;
    }

    @Override
    public void validate(Object value) {
        if (!(value instanceof Double)) {
            throw new IllegalArgumentException("Expected Double, got " + value.getClass().getName());
        }
    }

    @Override
    public byte[] serialize(Object value) {
        ByteBuffer buffer = ByteBuffer.allocate(8);
        buffer.putDouble((Double) value);
        return buffer.array();
    }

    @Override
    public Object deserialize(byte[] data) {
        return ByteBuffer.wrap(data).getDouble();
    }

    @Override
    public Object deserialize(ByteBuffer buffer) {
        return buffer.getDouble();
    }

    @Override
    public void serialize(Object value, ByteBuffer buffer) {
        buffer.putDouble((Double) value);
    }
}
