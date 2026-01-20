package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;

public class KyloBoolean extends KyloType {

    @Override
    public int getFixedSize() {
        return 1;
    }

    @Override
    public boolean isVariableLength() {
        return false;
    }

    @Override
    public void validate(Object value) {
        if (!(value instanceof Boolean)) {
            throw new IllegalArgumentException("Expected Boolean for KyloBoolean");
        }
    }

    @Override
    public byte[] serialize(Object value) {
        byte[] b = new byte[1];
        b[0] = (Boolean) value ? (byte) 1 : (byte) 0;
        return b;
    }

    @Override
    public Object deserialize(byte[] data) {
        return data[0] != 0;
    }

    @Override
    public Object deserialize(ByteBuffer buffer) {
        return buffer.get() != 0;
    }

    @Override
    public void serialize(Object value, ByteBuffer buffer) {
        buffer.put((Boolean) value ? (byte) 1 : (byte) 0);
    }
}
