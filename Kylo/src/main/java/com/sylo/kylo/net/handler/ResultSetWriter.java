package com.sylo.kylo.net.handler;

import com.sylo.kylo.core.catalog.Column;
import com.sylo.kylo.core.catalog.Schema;
import com.sylo.kylo.net.protocol.MySQLPacket;
import com.sylo.kylo.net.protocol.PacketBuilder;
import java.io.IOException;
import java.io.OutputStream;
import java.nio.ByteBuffer;
import java.nio.ByteOrder;
import java.util.List;

public class ResultSetWriter {

    // MySQL Types
    public static final int MYSQL_TYPE_DECIMAL = 0x00;
    public static final int MYSQL_TYPE_LONG = 0x03; // INT
    public static final int MYSQL_TYPE_FLOAT = 0x04;
    public static final int MYSQL_TYPE_DOUBLE = 0x05;
    public static final int MYSQL_TYPE_TIMESTAMP = 0x07;
    public static final int MYSQL_TYPE_LONGLONG = 0x08; // BIGINT
    public static final int MYSQL_TYPE_DATE = 0x0A;
    public static final int MYSQL_TYPE_TIME = 0x0B;
    public static final int MYSQL_TYPE_DATETIME = 0x0C;
    public static final int MYSQL_TYPE_VARCHAR = 0x0F;
    public static final int MYSQL_TYPE_BLOB = 0xFC;
    public static final int MYSQL_TYPE_VAR_STRING = 0xFD;
    public static final int MYSQL_TYPE_STRING = 0xFE;

    public void writeResultSet(OutputStream out, List<Object[]> rows, Schema schema, byte sequenceId)
            throws IOException {
        // 1. Column Count Packet
        ByteBuffer countBuf = ByteBuffer.allocate(20).order(ByteOrder.LITTLE_ENDIAN);
        MySQLPacket.writeLenEncInt(countBuf, schema.getColumnCount());
        byte[] countPayload = new byte[countBuf.position()];
        countBuf.flip();
        countBuf.get(countPayload);
        MySQLPacket.writePacket(out, countPayload, ++sequenceId);

        // 2. Column Definitions
        for (int i = 0; i < schema.getColumnCount(); i++) {
            Column col = schema.getColumn(i);
            writeColumnDefinition(out, col, ++sequenceId);
        }

        // 3. EOF Packet (End of Columns)
        MySQLPacket.writePacket(out, PacketBuilder.buildEof(), ++sequenceId);

        // 4. Rows
        for (Object[] row : rows) {
            writeRow(out, row, schema, ++sequenceId);
        }

        // 5. EOF Packet (End of Rows)
        MySQLPacket.writePacket(out, PacketBuilder.buildEof(), ++sequenceId);
    }

    private void writeColumnDefinition(OutputStream out, Column col, byte seq) throws IOException {
        ByteBuffer buf = ByteBuffer.allocate(512).order(ByteOrder.LITTLE_ENDIAN);

        // Catalog "def"
        MySQLPacket.writeLenEncString(buf, "def");
        // Schema (Database) - fixed "kylo" for now or pass context
        MySQLPacket.writeLenEncString(buf, "kylo");
        // Table (Virtual or real)
        MySQLPacket.writeLenEncString(buf, "result_table");
        // Org Table
        MySQLPacket.writeLenEncString(buf, "result_table");
        // Name
        MySQLPacket.writeLenEncString(buf, col.getName());
        // Org Name
        MySQLPacket.writeLenEncString(buf, col.getName());

        // Length of fixed fields (0x0C)
        MySQLPacket.writeLenEncInt(buf, 0x0C);

        // Charset (33 utf8)
        MySQLPacket.writeInt2(buf, 33);
        // Column Length (Max)
        MySQLPacket.writeInt4(buf, 255); // Simplified
        // Column Type
        MySQLPacket.writeInt1(buf, mapKyloTypeToMySQL(col.getType()));
        // Flags
        MySQLPacket.writeInt2(buf, 0);
        // Decimals
        MySQLPacket.writeInt1(buf, 0);
        // Filler
        MySQLPacket.writeInt2(buf, 0);

        byte[] payload = new byte[buf.position()];
        buf.flip();
        buf.get(payload);
        MySQLPacket.writePacket(out, payload, seq);
    }

    private void writeRow(OutputStream out, Object[] row, Schema schema, byte seq) throws IOException {
        ByteBuffer buf = ByteBuffer.allocate(4096).order(ByteOrder.LITTLE_ENDIAN); // Hopefully enough for a row

        for (int i = 0; i < row.length; i++) {
            Object val = row[i];
            // MySQL Text Protocol sends everything as Length Encoded Strings!
            String sVal = val == null ? null : val.toString();
            MySQLPacket.writeLenEncString(buf, sVal);
        }

        byte[] payload = new byte[buf.position()];
        buf.flip();
        buf.get(payload);
        MySQLPacket.writePacket(out, payload, seq);
    }

    private int mapKyloTypeToMySQL(com.sylo.kylo.core.structure.KyloType type) {
        if (type instanceof com.sylo.kylo.core.structure.KyloInt)
            return MYSQL_TYPE_LONG;
        if (type instanceof com.sylo.kylo.core.structure.KyloBigInt)
            return MYSQL_TYPE_LONGLONG;
        if (type instanceof com.sylo.kylo.core.structure.KyloFloat)
            return MYSQL_TYPE_FLOAT;
        if (type instanceof com.sylo.kylo.core.structure.KyloDouble)
            return MYSQL_TYPE_DOUBLE;
        if (type instanceof com.sylo.kylo.core.structure.KyloVarchar)
            return MYSQL_TYPE_VAR_STRING;
        if (type instanceof com.sylo.kylo.core.structure.KyloText)
            return MYSQL_TYPE_STRING;
        if (type instanceof com.sylo.kylo.core.structure.KyloBlob)
            return MYSQL_TYPE_BLOB;
        if (type instanceof com.sylo.kylo.core.structure.KyloDate)
            return MYSQL_TYPE_DATE;
        if (type instanceof com.sylo.kylo.core.structure.KyloTime)
            return MYSQL_TYPE_TIME;
        if (type instanceof com.sylo.kylo.core.structure.KyloDateTime)
            return MYSQL_TYPE_DATETIME;
        if (type instanceof com.sylo.kylo.core.structure.KyloTimestamp)
            return MYSQL_TYPE_TIMESTAMP;
        if (type instanceof com.sylo.kylo.core.structure.KyloBoolean)
            return MYSQL_TYPE_LONG; // Tinyint?

        return MYSQL_TYPE_VAR_STRING; // Default
    }
}
