package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;

public class KyloBlob extends KyloType {

    @Override
    public int getFixedSize() {
        return -1;
    }

    @Override
    public boolean isVariableLength() {
        return true;
    }

    @Override
    public void validate(Object value) {
        if (!(value instanceof byte[])) {
            throw new IllegalArgumentException("Expected byte[] for KyloBlob");
        }
    }

    @Override
    public byte[] serialize(Object value) {
        byte[] bytes = (byte[]) value;
        ByteBuffer buffer = ByteBuffer.allocate(4 + bytes.length);
        buffer.putInt(bytes.length);
        buffer.put(bytes);
        return buffer.array();
    }

    @Override
    public Object deserialize(byte[] data) {
        return deserialize(ByteBuffer.wrap(data));
    }

    @Override
    public Object deserialize(ByteBuffer buffer) {
        int len = buffer.getInt();
        byte[] bytes = new byte[len];
        buffer.get(bytes);
        return bytes;
    }

    @Override
    public void serialize(Object value, ByteBuffer buffer) {
        byte[] bytes = (byte[]) value;
        buffer.putInt(bytes.length);
        buffer.put(bytes);
    }

    @Override
    public String toString() {
        return "BLOB";
    }
}
