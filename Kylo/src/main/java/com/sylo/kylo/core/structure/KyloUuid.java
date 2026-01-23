package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;
import java.util.UUID;

public class KyloUuid extends KyloType {

    @Override
    public int getFixedSize() {
        return 16;
    }

    @Override
    public boolean isVariableLength() {
        return false;
    }

    @Override
    public void validate(Object value) {
        if (!(value instanceof UUID)) {
            throw new IllegalArgumentException("Expected UUID for KyloUuid");
        }
    }

    @Override
    public byte[] serialize(Object value) {
        UUID uuid = (UUID) value;
        ByteBuffer buffer = ByteBuffer.allocate(16);
        buffer.putLong(uuid.getMostSignificantBits());
        buffer.putLong(uuid.getLeastSignificantBits());
        return buffer.array();
    }

    @Override
    public Object deserialize(byte[] data) {
        return deserialize(ByteBuffer.wrap(data));
    }

    @Override
    public Object deserialize(ByteBuffer buffer) {
        long msb = buffer.getLong();
        long lsb = buffer.getLong();
        return new UUID(msb, lsb);
    }

    @Override
    public void serialize(Object value, ByteBuffer buffer) {
        UUID uuid = (UUID) value;
        buffer.putLong(uuid.getMostSignificantBits());
        buffer.putLong(uuid.getLeastSignificantBits());
    }

    @Override
    public String toString() {
        return "UUID";
    }
}
