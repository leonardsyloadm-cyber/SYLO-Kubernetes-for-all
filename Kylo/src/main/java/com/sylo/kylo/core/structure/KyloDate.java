package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;
import java.time.LocalDate;

public class KyloDate extends KyloType {

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
        if (!(value instanceof LocalDate)) {
            throw new IllegalArgumentException("Expected LocalDate for KyloDate");
        }
    }

    @Override
    public byte[] serialize(Object value) {
        int epochDay = (int) ((LocalDate) value).toEpochDay();
        ByteBuffer buffer = ByteBuffer.allocate(4);
        buffer.putInt(epochDay);
        return buffer.array();
    }

    @Override
    public Object deserialize(byte[] data) {
        return deserialize(ByteBuffer.wrap(data));
    }

    @Override
    public Object deserialize(ByteBuffer buffer) {
        int epochDay = buffer.getInt();
        return LocalDate.ofEpochDay(epochDay);
    }

    @Override
    public void serialize(Object value, ByteBuffer buffer) {
        buffer.putInt((int) ((LocalDate) value).toEpochDay());
    }

    @Override
    public String toString() {
        return "DATE";
    }
}
