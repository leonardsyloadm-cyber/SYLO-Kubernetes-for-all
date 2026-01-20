package com.sylo.kylo.core.structure;

import java.nio.ByteBuffer;

public class RowHeader {
    public static final int SIZE = 8 + 8 + 2; // xmin (8), xmax (8), flags (2)

    private long xmin;
    private long xmax;
    private boolean isDeleted;

    public RowHeader(long xmin, long xmax, boolean isDeleted) {
        this.xmin = xmin;
        this.xmax = xmax;
        this.isDeleted = isDeleted;
    }

    public RowHeader() {
        this(0, 0, false);
    }

    public long getXmin() {
        return xmin;
    }

    public void setXmin(long xmin) {
        this.xmin = xmin;
    }

    public long getXmax() {
        return xmax;
    }

    public void setXmax(long xmax) {
        this.xmax = xmax;
    }

    public boolean isDeleted() {
        return isDeleted;
    }

    public void setDeleted(boolean deleted) {
        isDeleted = deleted;
    }

    public byte[] serialize() {
        ByteBuffer buffer = ByteBuffer.allocate(SIZE);
        buffer.putLong(xmin);
        buffer.putLong(xmax);
        short flags = 0;
        if (isDeleted) {
            flags |= 1;
        }
        buffer.putShort(flags);
        return buffer.array();
    }

    public static RowHeader deserialize(ByteBuffer buffer) {
        long xmin = buffer.getLong();
        long xmax = buffer.getLong();
        short flags = buffer.getShort();
        boolean isDeleted = (flags & 1) != 0;
        return new RowHeader(xmin, xmax, isDeleted);
    }
    
    public void serialize(ByteBuffer buffer) {
        buffer.putLong(xmin);
        buffer.putLong(xmax);
        short flags = 0;
        if (isDeleted) {
            flags |= 1;
        }
        buffer.putShort(flags);
    }
}
