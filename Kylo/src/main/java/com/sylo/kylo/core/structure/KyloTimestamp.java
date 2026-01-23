package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;
import java.time.Instant;

public class KyloTimestamp extends KyloType {

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
        if (!(value instanceof Instant)) {
            throw new IllegalArgumentException("Expected java.time.Instant for KyloTimestamp");
        }
    }

    @Override
    public byte[] serialize(Object value) {
        long millis = ((Instant) value).toEpochMilli();
        ByteBuffer buffer = ByteBuffer.allocate(8);
        buffer.putLong(millis);
        return buffer.array();
    }

    @Override
    public Object deserialize(byte[] data) {
        return deserialize(ByteBuffer.wrap(data));
    }

    @Override
    public Object deserialize(ByteBuffer buffer) {
        long millis = buffer.getLong();
        return Instant.ofEpochMilli(millis);
    }

    @Override
    public void serialize(Object value, ByteBuffer buffer) {
        buffer.putLong(((Instant) value).toEpochMilli());
    }

    @Override
    public String toString() {
        return "TIMESTAMP";
    }
}
