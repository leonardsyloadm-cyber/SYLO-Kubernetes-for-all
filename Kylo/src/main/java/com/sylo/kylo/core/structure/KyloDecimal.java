package com.sylo.kylo.core.structure;

import java.math.BigDecimal;
import java.math.BigInteger;
import java.nio.ByteBuffer;

public class KyloDecimal extends KyloType {

    @Override
    public int getFixedSize() {
        return -1; // Variable length
    }

    @Override
    public boolean isVariableLength() {
        return true;
    }

    @Override
    public void validate(Object value) {
        if (!(value instanceof BigDecimal)) {
            throw new IllegalArgumentException("Expected BigDecimal, got " + value.getClass().getName());
        }
    }

    @Override
    public byte[] serialize(Object value) {
        BigDecimal decimal = (BigDecimal) value;
        byte[] unscaled = decimal.unscaledValue().toByteArray();
        int scale = decimal.scale();
        
        ByteBuffer buffer = ByteBuffer.allocate(4 + 4 + unscaled.length);
        buffer.putInt(scale);
        buffer.putInt(unscaled.length);
        buffer.put(unscaled);
        return buffer.array();
    }

    @Override
    public Object deserialize(byte[] data) {
        return deserialize(ByteBuffer.wrap(data));
    }

    @Override
    public Object deserialize(ByteBuffer buffer) {
        int scale = buffer.getInt();
        int len = buffer.getInt();
        byte[] unscaled = new byte[len];
        buffer.get(unscaled);
        BigInteger bi = new BigInteger(unscaled);
        return new BigDecimal(bi, scale);
    }

    @Override
    public void serialize(Object value, ByteBuffer buffer) {
        BigDecimal decimal = (BigDecimal) value;
        byte[] unscaled = decimal.unscaledValue().toByteArray();
        int scale = decimal.scale();
        
        buffer.putInt(scale);
        buffer.putInt(unscaled.length);
        buffer.put(unscaled);
    }
}
