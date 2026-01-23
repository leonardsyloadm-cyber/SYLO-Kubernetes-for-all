package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;
import java.time.LocalDate;
import java.time.LocalDateTime;
import java.time.LocalTime;

public class KyloDateTime extends KyloType {

    @Override
    public int getFixedSize() {
        return 12; // 4 (Date) + 8 (Time)
    }

    @Override
    public boolean isVariableLength() {
        return false;
    }

    @Override
    public void validate(Object value) {
        if (!(value instanceof LocalDateTime)) {
            throw new IllegalArgumentException("Expected LocalDateTime for KyloDateTime");
        }
    }

    @Override
    public byte[] serialize(Object value) {
        LocalDateTime dt = (LocalDateTime) value;
        ByteBuffer buffer = ByteBuffer.allocate(12);
        buffer.putInt((int) dt.toLocalDate().toEpochDay());
        buffer.putLong(dt.toLocalTime().toNanoOfDay());
        return buffer.array();
    }

    @Override
    public Object deserialize(byte[] data) {
        return deserialize(ByteBuffer.wrap(data));
    }

    @Override
    public Object deserialize(ByteBuffer buffer) {
        int epochDay = buffer.getInt();
        long nanoOfDay = buffer.getLong();
        return LocalDateTime.of(LocalDate.ofEpochDay(epochDay), LocalTime.ofNanoOfDay(nanoOfDay));
    }

    @Override
    public void serialize(Object value, ByteBuffer buffer) {
        LocalDateTime dt = (LocalDateTime) value;
        buffer.putInt((int) dt.toLocalDate().toEpochDay());
        buffer.putLong(dt.toLocalTime().toNanoOfDay());
    }

    @Override
    public String toString() {
        return "DATETIME";
    }
}
