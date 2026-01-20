package com.sylo.kylo.net.protocol;

import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.nio.ByteBuffer;
import java.nio.charset.StandardCharsets;

public class MySQLPacket {

    public static byte[] readPacket(InputStream in) throws IOException {
        // Header: 3 bytes length + 1 byte sequence
        byte[] header = new byte[4];
        int read = in.read(header);
        if (read < 4)
            return null; // EOF or error

        int length = (header[0] & 0xFF) | ((header[1] & 0xFF) << 8) | ((header[2] & 0xFF) << 16);

        byte[] payload = new byte[length];
        int totalRead = 0;
        while (totalRead < length) {
            int r = in.read(payload, totalRead, length - totalRead);
            if (r == -1)
                break;
            totalRead += r;
        }
        return payload;
    }

    public static void writePacket(OutputStream out, byte[] payload, byte sequenceId) throws IOException {
        int length = payload.length;
        // 3 bytes length (Little Endian)
        out.write(length & 0xFF);
        out.write((length >> 8) & 0xFF);
        out.write((length >> 16) & 0xFF);
        // 1 byte sequence
        out.write(sequenceId);
        // Payload
        out.write(payload);
        out.flush();
    }

    // --- Little Endian Writers ---

    public static void writeInt1(ByteBuffer buffer, int value) {
        buffer.put((byte) (value & 0xFF));
    }

    public static void writeInt2(ByteBuffer buffer, int value) {
        buffer.putShort((short) value); // ByteBuffer default is BigEndian, need to set Order or do manually
        // We will assume caller sets order or we do manual
    }

    public static void writeInt3(ByteBuffer buffer, int value) {
        buffer.put((byte) (value & 0xFF));
        buffer.put((byte) ((value >> 8) & 0xFF));
        buffer.put((byte) ((value >> 16) & 0xFF));
    }

    public static void writeInt4(ByteBuffer buffer, int value) {
        buffer.putInt(value);
    }

    public static void writeInt8(ByteBuffer buffer, long value) {
        buffer.putLong(value);
    }

    // --- High Level Builders Helpers ---

    // Length Encoded Integer
    public static void writeLenEncInt(ByteBuffer buffer, long value) {
        if (value < 251) {
            buffer.put((byte) value);
        } else if (value < 65536) {
            buffer.put((byte) 0xFC);
            buffer.put((byte) (value & 0xFF));
            buffer.put((byte) ((value >> 8) & 0xFF));
        } else if (value < 16777216) {
            buffer.put((byte) 0xFD);
            buffer.put((byte) (value & 0xFF));
            buffer.put((byte) ((value >> 8) & 0xFF));
            buffer.put((byte) ((value >> 16) & 0xFF));
        } else {
            buffer.put((byte) 0xFE);
            buffer.putLong(value); // This might be wrong order if buffer is not LE
        }
    }

    // Length Encoded String
    public static void writeLenEncString(ByteBuffer buffer, String s) {
        if (s == null) {
            buffer.put((byte) 0xFB); // NULL
            return;
        }
        byte[] b = s.getBytes(StandardCharsets.UTF_8);
        writeLenEncInt(buffer, b.length);
        buffer.put(b);
    }

    public static void writeStringNullTerminated(ByteBuffer buffer, String s) {
        if (s != null)
            buffer.put(s.getBytes(StandardCharsets.UTF_8));
        buffer.put((byte) 0x00);
    }
}
