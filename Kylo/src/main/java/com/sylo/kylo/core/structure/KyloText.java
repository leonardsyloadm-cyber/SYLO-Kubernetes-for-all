package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;
import java.nio.charset.StandardCharsets;

public class KyloText extends KyloType {

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
        if (!(value instanceof String)) {
            throw new IllegalArgumentException("Expected String for KyloText");
        }
    }

    @Override
    public byte[] serialize(Object value) {
        byte[] bytes = ((String) value).getBytes(StandardCharsets.UTF_8);
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
        return new String(bytes, StandardCharsets.UTF_8);
    }

    @Override
    public void serialize(Object value, ByteBuffer buffer) {
        byte[] bytes = ((String) value).getBytes(StandardCharsets.UTF_8);
        buffer.putInt(bytes.length);
        buffer.put(bytes);
    }
}
