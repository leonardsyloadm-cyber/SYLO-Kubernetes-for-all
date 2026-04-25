package com.sylo.kylo.core.transaction;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.nio.ByteBuffer;
import java.nio.channels.FileChannel;
import java.nio.charset.StandardCharsets;

public class WriteAheadLog {
    private static FileChannel channel;

    static {
        try {
            String basePath = com.sylo.kylo.core.storage.StorageConfig.BASE_DIR;
            File f = new File(basePath, "wal.log");
            FileOutputStream fos = new FileOutputStream(f, true); // append mode
            channel = fos.getChannel();
        } catch (IOException e) {
            System.err.println("Failed to initialize WAL: " + e.getMessage());
        }
    }

    public static synchronized void appendTx(String sessionId, String operations) {
        if (channel == null) return;
        try {
            String logEntry = "TX BEGIN|" + sessionId + "\n" + operations + "TX COMMIT|" + sessionId + "\n";
            ByteBuffer buffer = ByteBuffer.wrap(logEntry.getBytes(StandardCharsets.UTF_8));
            while (buffer.hasRemaining()) {
                channel.write(buffer);
            }
            // Force fsync to disk to guarantee Durability
            channel.force(true); 
        } catch (IOException e) {
            System.err.println("WAL Write Error: " + e.getMessage());
        }
    }
}
