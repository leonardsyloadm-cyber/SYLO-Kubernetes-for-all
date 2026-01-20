package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;
import java.nio.charset.StandardCharsets;
import java.util.Arrays;

public class KyloChar extends KyloType {

    private final int length;

    public KyloChar(int length) {
        this.length = length;
    }

    @Override
    public int getFixedSize() {
        return length;
    }

    @Override
    public boolean isVariableLength() {
        return false;
    }

    @Override
    public void validate(Object value) {
        if (!(value instanceof String)) {
            throw new IllegalArgumentException("Expected String for KyloChar, got " + value.getClass().getName());
        }
        if (((String) value).length() > length) {
            throw new IllegalArgumentException("String too long for KyloChar(" + length + ")");
        }
    }

    @Override
    public byte[] serialize(Object value) {
        String s = (String) value;
        byte[] strBytes = s.getBytes(StandardCharsets.UTF_8);
        byte[] padded = Arrays.copyOf(strBytes, length);
        // Pad with spaces (byte 32) if needed
        if (strBytes.length < length) {
            Arrays.fill(padded, strBytes.length, length, (byte) 32);
        }
        return padded;
    }

    @Override
    public Object deserialize(byte[] data) {
        // Trim spaces? Usually CHAR returns padded or trimmed depending on SQL standard. 
        // We'll return trimmed for convenience, or raw. Let's return string trimming trailing spaces.
        // Actually, strictly CHAR keeps spaces, but often users want trimmed.
        // Let's keep it simple: new String(data).trim()
        return new String(data, StandardCharsets.UTF_8).trim(); 
    }

    @Override
    public Object deserialize(ByteBuffer buffer) {
        byte[] data = new byte[length];
        buffer.get(data);
        return new String(data, StandardCharsets.UTF_8).trim();
    }

    @Override
    public void serialize(Object value, ByteBuffer buffer) {
        buffer.put(serialize(value));
    }
}
