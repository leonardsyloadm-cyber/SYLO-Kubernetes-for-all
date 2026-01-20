package com.sylo.kylo.net.handler;

import com.sylo.kylo.core.execution.ExecutionEngine;
import com.sylo.kylo.net.protocol.MySQLPacket;
import com.sylo.kylo.net.protocol.PacketBuilder;
import java.io.OutputStream;
import java.nio.charset.StandardCharsets;
import java.io.IOException;

public class CommandDispatcher {
    
    // Commands
    public static final byte COM_QUIT = 0x01;
    public static final byte COM_INIT_DB = 0x02;
    public static final byte COM_QUERY = 0x03;
    public static final byte COM_PING = 0x0E;

    private final KyloBridge bridge;

    public CommandDispatcher(ExecutionEngine engine) {
        this.bridge = new KyloBridge(engine);
    }

    public void dispatch(byte command, byte[] payload, OutputStream out, byte sequenceId) throws IOException {
        switch (command) {
            case COM_QUIT:
                // Caller should detect this return/break loop
                return;
            
            case COM_PING:
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++sequenceId);
                break;

            case COM_INIT_DB:
                // String db = new String(payload, 1, payload.length-1, StandardCharsets.UTF_8);
                // System.out.println("USE DB: " + db);
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++sequenceId);
                break;
                
            case COM_QUERY:
                String query = new String(payload, 1, payload.length - 1, StandardCharsets.UTF_8);
                System.out.println("SQL RECEIVED: " + query);
                
                bridge.executeQuery(query, out, sequenceId);
                break;
                
            default:
                MySQLPacket.writePacket(out, PacketBuilder.buildError(1000, "Unknown Command: " + command), ++sequenceId);
        }
    }
}
