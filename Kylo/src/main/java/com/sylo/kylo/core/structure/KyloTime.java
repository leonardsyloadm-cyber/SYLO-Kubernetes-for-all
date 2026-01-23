package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;
import java.time.LocalTime;

public class KyloTime extends KyloType {

    @Override
    public int getFixedSize() {
        return 8; // Nano of day fits in long
    }

    @Override
    public boolean isVariableLength() {
        return false;
    }

    @Override
    public void validate(Object value) {
        if (!(value instanceof LocalTime)) {
            throw new IllegalArgumentException("Expected LocalTime for KyloTime");
        }
    }

    @Override
    public byte[] serialize(Object value) {
        long nanoOfDay = ((LocalTime) value).toNanoOfDay();
        ByteBuffer buffer = ByteBuffer.allocate(8);
        buffer.putLong(nanoOfDay);
        return buffer.array();
    }

    @Override
    public Object deserialize(byte[] data) {
        return deserialize(ByteBuffer.wrap(data));
    }

    @Override
    public Object deserialize(ByteBuffer buffer) {
        long nanoOfDay = buffer.getLong();
        return LocalTime.ofNanoOfDay(nanoOfDay);
    }

    @Override
    public void serialize(Object value, ByteBuffer buffer) {
        buffer.putLong(((LocalTime) value).toNanoOfDay());
    }

    @Override
    public String toString() {
        return "TIME";
    }
}
