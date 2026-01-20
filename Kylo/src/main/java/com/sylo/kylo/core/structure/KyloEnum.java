package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;
import java.util.HashMap;
import java.util.Map;

public class KyloEnum extends KyloType {

    private final Map<String, Integer> stringToInt;
    private final Map<Integer, String> intToString;
    private final boolean useTwoBytes;

    public KyloEnum(Map<String, Integer> mapping) {
        this.stringToInt = new HashMap<>(mapping);
        this.intToString = new HashMap<>();
        int maxVal = 0;
        for (Map.Entry<String, Integer> entry : mapping.entrySet()) {
            intToString.put(entry.getValue(), entry.getKey());
            if (entry.getValue() > maxVal) maxVal = entry.getValue();
        }
        // If maxVal > 127 (byte), use 2 bytes
        this.useTwoBytes = maxVal > 127; 
        // Note: prompt says 1 or 2 bytes. We'll use strict logic.
    }

    @Override
    public int getFixedSize() {
        return useTwoBytes ? 2 : 1;
    }

    @Override
    public boolean isVariableLength() {
        return false;
    }

    @Override
    public void validate(Object value) {
        if (!(value instanceof String)) {
            throw new IllegalArgumentException("Expected String for KyloEnum");
        }
        if (!stringToInt.containsKey(value)) {
            throw new IllegalArgumentException("Invalid Enum value: " + value);
        }
    }

    @Override
    public byte[] serialize(Object value) {
        int id = stringToInt.get(value);
        int size = getFixedSize();
        ByteBuffer buffer = ByteBuffer.allocate(size);
        if (useTwoBytes) {
            buffer.putShort((short) id);
        } else {
            buffer.put((byte) id);
        }
        return buffer.array();
    }

    @Override
    public Object deserialize(byte[] data) {
         return deserialize(ByteBuffer.wrap(data));
    }

    @Override
    public Object deserialize(ByteBuffer buffer) {
        int id;
        if (useTwoBytes) {
            id = buffer.getShort();
        } else {
            id = buffer.get();
        }
        String val = intToString.get(id);
        if (val == null) {
            throw new IllegalStateException("Unknown Enum ID: " + id);
        }
        return val;
    }

    @Override
    public void serialize(Object value, ByteBuffer buffer) {
        int id = stringToInt.get(value);
        if (useTwoBytes) {
            buffer.putShort((short) id);
        } else {
            buffer.put((byte) id);
        }
    }
}
