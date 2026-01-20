package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;
import java.time.Year;

public class KyloYear extends KyloType {

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
        if (!(value instanceof Year)) {
            throw new IllegalArgumentException("Expected java.time.Year for KyloYear");
        }
        int y = ((Year) value).getValue();
        if (y < 1901 || y > 2155) {
             // 1901 maps to 1, 2155 maps to 255. 0 is ? usually error or null in 1-byte logic if strict.
             // Or allow 1900 as 0? Common SQL behavior.
             // Let's allow 1900-2155.
             if (y < 1900 || y > 2155) {
                 throw new IllegalArgumentException("Year out of range (1900-2155) for 1-byte storage");
             }
        }
    }

    @Override
    public byte[] serialize(Object value) {
        int y = ((Year) value).getValue();
        byte b = (byte) (y - 1900);
        return new byte[]{b};
    }

    @Override
    public Object deserialize(byte[] data) {
         return deserialize(ByteBuffer.wrap(data));
    }

    @Override
    public Object deserialize(ByteBuffer buffer) {
        byte b = buffer.get();
        int y = (b & 0xFF) + 1900;
        return Year.of(y);
    }

    @Override
    public void serialize(Object value, ByteBuffer buffer) {
        int y = ((Year) value).getValue();
        buffer.put((byte) (y - 1900));
    }
}
