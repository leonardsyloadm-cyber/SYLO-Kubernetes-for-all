package com.sylo.kylo.net.protocol;

import java.nio.ByteBuffer;
import java.nio.ByteOrder;
import java.util.Arrays;

public class PacketBuilder {

    private static ByteBuffer createBuffer(int capacity) {
        return ByteBuffer.allocate(capacity).order(ByteOrder.LITTLE_ENDIAN);
    }

    public static byte[] buildHandshake(int connectionId, String authPluginData) {
        // Estimate size: ~100 bytes is usually enough for initial handshake
        ByteBuffer buf = createBuffer(256);

        // 1. Protocol Version (10)
        MySQLPacket.writeInt1(buf, 10);

        // 2. Server Version (Null Terminated)
        MySQLPacket.writeStringNullTerminated(buf, "8.0.30-KyloDB");

        // 3. Thread ID (4 bytes)
        MySQLPacket.writeInt4(buf, connectionId);

        // 4. Auth Plugin Data Part 1 (8 bytes) - Salt
        byte[] salt = authPluginData.getBytes();
        buf.put(Arrays.copyOfRange(salt, 0, 8)); // First 8 bytes
        buf.put((byte) 0); // Filter

        // 5. Capabilities Flags (Lower 2 bytes)
        // CLIENT_LONG_PASSWORD | CLIENT_FOUND_ROWS | CLIENT_LONG_FLAG | CLIENT_CONNECT_WITH_DB | CLIENT_ODBC | CLIENT_LOCAL_FILES | CLIENT_IGNORE_SPACE | CLIENT_PROTOCOL_41 | CLIENT_INTERACTIVE | CLIENT_SSL | CLIENT_IGNORE_SIGPIPE | CLIENT_TRANSACTIONS | CLIENT_RESERVED | CLIENT_SECURE_CONNECTION
        // Commonly: 0xF7FF or similar. Let's use a standard set.
        // 0x0200 (Protocol 4.1) is critical.
        // IMPORTANT: Unset CLIENT_SSL (0x0800) to prevent client trying SSL handshake
        // IMPORTANT: Set CLIENT_PLUGIN_AUTH (0x00080000) because we send plugin name
        int capabilities = 0xF7FF | 0x00080000; 
        
        // Lower 2 bytes
        MySQLPacket.writeInt2(buf, capabilities & 0xFFFF);

        // 6. Character Set (1 byte) - 45 = utf8mb4_general_ci ? or 33=utf8_general_ci. Let's use 33 (utf8) or 45.
        MySQLPacket.writeInt1(buf, 45);

        // 7. Status Flags (2 bytes) - AUTOCOMMIT usually on (0x0002)
        MySQLPacket.writeInt2(buf, 0x0002);

        // 8. Capabilities Flags (Upper 2 bytes)
        MySQLPacket.writeInt2(buf, capabilities >> 16);

        // 9. Auth Plugin Data Length (1 byte) - Total length of salt
        MySQLPacket.writeInt1(buf, 21); // 8 + 12 + 1 (null) usually? Or just 21.

        // 10. Reserved (10 bytes) - Zeros
        buf.put(new byte[10]);

        // 11. Auth Plugin Data Part 2 (12 bytes usually) - Rest of salt
        // If salt is longer than 8, write rest here.
        // We assume 20 byte salt usually.
        // buf.put(rest of salt);
        // Kylo Auth simple: just 8 bytes for now or fake it.
        // MySQL expects at least 21 bytes usually if secure auth.
        // Let's ensure salt is long enough.
        if (salt.length > 8) {
            buf.put(Arrays.copyOfRange(salt, 8, Math.min(salt.length, 20)));
        } else {
             buf.put(new byte[12]); // Padding
        }
        buf.put((byte) 0); // Null terminator for auth plugin data

        // 12. Auth Plugin Name (Null Terminated)
        MySQLPacket.writeStringNullTerminated(buf, "mysql_native_password");

        byte[] res = new byte[buf.position()];
        buf.flip();
        buf.get(res);
        return res;
    }

    public static byte[] buildOk(long affectedRows, long lastInsertId) {
        ByteBuffer buf = createBuffer(100);
        // Header
        MySQLPacket.writeInt1(buf, 0x00);
        // Affected Rows
        MySQLPacket.writeLenEncInt(buf, affectedRows);
        // Last Insert ID
        MySQLPacket.writeLenEncInt(buf, lastInsertId);
        // Status Flags
        MySQLPacket.writeInt2(buf, 0x0002); // Autocommit
        // Warnings
        MySQLPacket.writeInt2(buf, 0);

        byte[] res = new byte[buf.position()];
        buf.flip();
        buf.get(res);
        return res;
    }

    public static byte[] buildError(int errorCode, String message) {
        ByteBuffer buf = createBuffer(256 + message.length());
        // Header
        MySQLPacket.writeInt1(buf, 0xFF);
        // Error Code (2 bytes)
        MySQLPacket.writeInt2(buf, errorCode);
        // SQL State Marker
        buf.put((byte) '#');
        // SQL State (5 bytes)
        buf.put("HY000".getBytes()); // Generic error
        // Message
        buf.put(message.getBytes());

        byte[] res = new byte[buf.position()];
        buf.flip();
        buf.get(res);
        return res;
    }

    public static byte[] buildEof() {
        ByteBuffer buf = createBuffer(10);
        // Header
        MySQLPacket.writeInt1(buf, 0xFE);
        // Warnings
        MySQLPacket.writeInt2(buf, 0);
        // Status Flags
        MySQLPacket.writeInt2(buf, 0x0002);

        byte[] res = new byte[buf.position()];
        buf.flip();
        buf.get(res);
        return res;
    }
}
