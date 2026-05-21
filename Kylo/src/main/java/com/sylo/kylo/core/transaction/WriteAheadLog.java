package com.sylo.kylo.core.transaction;

import java.io.File;
import java.io.FileOutputStream;
import java.io.IOException;
import java.nio.ByteBuffer;
import java.nio.channels.FileChannel;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.util.List;
import java.util.zip.CRC32;
import com.sylo.kylo.core.execution.ExecutionEngine;

public class WriteAheadLog {
    private static FileChannel channel;
    private static File walFile;
    private static final long MAX_WAL_SIZE = 50 * 1024 * 1024; // 50MB before checkpoint

    static {
        try {
            String basePath = com.sylo.kylo.core.storage.StorageConfig.BASE_DIR;
            walFile = new File(basePath, "wal.log");
            FileOutputStream fos = new FileOutputStream(walFile, true); // append mode
            channel = fos.getChannel();
        } catch (IOException e) {
            System.err.println("Failed to initialize WAL: " + e.getMessage());
        }
    }
    
    public static void recover(ExecutionEngine engine) {
        if (walFile == null || !walFile.exists()) return;
        System.out.println("🔄 Checking Write-Ahead Log for recovery...");
        try {
            List<String> lines = Files.readAllLines(walFile.toPath(), StandardCharsets.UTF_8);
            int committedCount = 0;
            boolean inTx = false;
            StringBuilder currentTxOps = new StringBuilder();
            
            for (String line : lines) {
                if (line.startsWith("TX BEGIN|")) {
                    inTx = true;
                    currentTxOps.setLength(0); // reset
                } else if (line.startsWith("TX COMMIT|")) {
                    if (inTx) {
                        // verify checksum
                        String[] parts = line.split("\\|");
                        if (parts.length >= 3) {
                            long expectedCrc = Long.parseLong(parts[2]);
                            CRC32 crc = new CRC32();
                            crc.update(currentTxOps.toString().getBytes(StandardCharsets.UTF_8));
                            if (crc.getValue() == expectedCrc) {
                                committedCount++;
                                // REDO Phase: In a full ARIES, we would parse currentTxOps and apply engine.insert/delete here
                            } else {
                                System.err.println("⚠️ WAL Warning: Torn write detected (Checksum mismatch). Skipping transaction.");
                            }
                        }
                    }
                    inTx = false;
                } else if (line.startsWith("TX ABORT|")) {
                    inTx = false; // Transaction aborted, skip redo
                } else if (inTx) {
                    currentTxOps.append(line).append("\n");
                }
            }
            if (committedCount > 0) {
                System.out.println("✅ WAL Recovery verified " + committedCount + " intact transactions.");
            }
            
            checkpointIfNeeded();
        } catch (Exception e) {
            System.err.println("❌ WAL Recovery Error: " + e.getMessage());
        }
    }

    public static synchronized void appendTx(String sessionId, String operations) {
        if (channel == null) return;
        try {
            // Include checksum for Torn Write prevention
            CRC32 crc = new CRC32();
            crc.update(operations.getBytes(StandardCharsets.UTF_8));
            long checksum = crc.getValue();
            
            String logEntry = "TX BEGIN|" + sessionId + "\n" + operations + "TX COMMIT|" + sessionId + "|" + checksum + "\n";
            ByteBuffer buffer = ByteBuffer.wrap(logEntry.getBytes(StandardCharsets.UTF_8));
            while (buffer.hasRemaining()) {
                channel.write(buffer);
            }
            // Force fsync to disk to guarantee Durability
            channel.force(true); 
            
            checkpointIfNeeded();
        } catch (IOException e) {
            System.err.println("WAL Write Error: " + e.getMessage());
        }
    }
    
    private static void checkpointIfNeeded() {
        try {
            if (channel != null && channel.size() > MAX_WAL_SIZE) {
                System.out.println("🧹 WAL size exceeded limit. Performing Checkpoint/Truncation...");
                // In ARIES, we write a Checkpoint record and truncate obsolete log segments.
                // For now, simple wipe after flushing bufferpool (BufferPool manager handles its own flush).
                channel.truncate(0);
                System.out.println("🧹 WAL Truncated successfully.");
            }
        } catch (IOException e) {
            System.err.println("WAL Checkpoint Error: " + e.getMessage());
        }
    }
}
