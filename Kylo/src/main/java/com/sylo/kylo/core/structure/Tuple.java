package com.sylo.kylo.core.structure;

import com.sylo.kylo.core.catalog.Schema;
import com.sylo.kylo.core.catalog.Column;
import java.nio.ByteBuffer;
import java.util.Arrays;
import java.util.BitSet;

public class Tuple {
    private RowHeader rowHeader;
    private Object[] values;

    public Tuple(RowHeader rowHeader, Object[] values) {
        this.rowHeader = rowHeader;
        this.values = values;
    }

    public RowHeader getRowHeader() {
        return rowHeader;
    }

    public Object[] getValues() {
        return values;
    }
    
    public Object getValue(int index) {
        return values[index];
    }

    public byte[] serialize(Schema schema) {
        // 1. Calculate size
        int headerSize = RowHeader.SIZE;
        int numCols = schema.getColumnCount();
        int nullMapSize = (numCols + 7) / 8;
        
        int dataSize = 0;
        for (int i = 0; i < numCols; i++) {
            Column col = schema.getColumn(i);
            Object val = values[i];
            if (val == null) {
                // If null, we don't store data? 
                // Usually we don't store bytes for nulls if they are variable.
                // If fixed, we might reserve space or not.
                // Let's assume we DO NOT store data for nulls to save space, relying on nullmap.
                continue;
            }
            // Add size of serialized data
            // KyloType.serialize returns byte[]
            byte[] b = col.getType().serialize(val);
            dataSize += b.length;
        }
        
        ByteBuffer buffer = ByteBuffer.allocate(headerSize + nullMapSize + dataSize);
        
        // 2. Write Header
        rowHeader.serialize(buffer);
        
        // 3. Write Null Bitmap
        BitSet nullBits = new BitSet(numCols);
        for (int i = 0; i < numCols; i++) {
            if (values[i] == null) {
                nullBits.set(i);
            }
        }
        byte[] nullBytes = nullBits.toByteArray();
        // BitSet.toByteArray returns a variable length array being the trimmed bytes.
        // We need exactly nullMapSize bytes.
        byte[] finalNullBytes = new byte[nullMapSize];
        System.arraycopy(nullBytes, 0, finalNullBytes, 0, Math.min(nullBytes.length, nullMapSize));
        buffer.put(finalNullBytes);
        
        // 4. Write Data
        for (int i = 0; i < numCols; i++) {
            if (values[i] == null) continue;
            Column col = schema.getColumn(i);
            byte[] b = col.getType().serialize(values[i]);
            buffer.put(b);
        }
        
        return buffer.array();
    }

    public static Tuple deserialize(byte[] data, Schema schema) {
        ByteBuffer buffer = ByteBuffer.wrap(data);
        
        // 1. Read Header
        RowHeader header = RowHeader.deserialize(buffer);
        
        // 2. Read Null Bitmap
        int numCols = schema.getColumnCount();
        int nullMapSize = (numCols + 7) / 8;
        byte[] nullBytes = new byte[nullMapSize];
        buffer.get(nullBytes);
        BitSet nullBits = BitSet.valueOf(nullBytes);
        
        Object[] values = new Object[numCols];
        
        // 3. Read Data
        for (int i = 0; i < numCols; i++) {
            if (nullBits.get(i)) {
                values[i] = null;
            } else {
                Column col = schema.getColumn(i);
                // KyloType.deserialize reads from buffer
                values[i] = col.getType().deserialize(buffer);
            }
        }
        
        return new Tuple(header, values);
    }
}
