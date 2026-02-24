package com.sylo.kylo.net;

import com.sylo.kylo.net.auth.AuthManager;
import com.sylo.kylo.net.handler.CommandDispatcher;
import com.sylo.kylo.net.protocol.MySQLPacket;
import com.sylo.kylo.net.protocol.PacketBuilder;
import java.io.BufferedInputStream;
import java.io.BufferedOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.ServerSocket;
import java.net.Socket;
import java.nio.charset.StandardCharsets;

import com.sylo.kylo.core.execution.ExecutionEngine;

public class KyloProtocolServer extends Thread {
    private final int port;
    private final ExecutionEngine engine;
    private boolean running = true;

    public KyloProtocolServer(int port, ExecutionEngine engine) {
        this.port = port;
        this.engine = engine;
    }

    @Override
    public void run() {
        try (ServerSocket serverSocket = new ServerSocket(port, 50, java.net.InetAddress.getByName("0.0.0.0"))) {
            System.out.println("🔌 KyloDB MySQL Interface listening on 0.0.0.0:" + port);

            int connectionId = 1;
            while (running) {
                try {
                    Socket clientSocket = serverSocket.accept();
                    System.out.println("➕ New Connection from " + clientSocket.getRemoteSocketAddress());

                    // Java 21 Virtual Threads!
                    Thread.ofVirtual().start(new ConnectionHandler(clientSocket, connectionId++, engine));
                } catch (IOException e) {
                    System.err.println("Error accepting connection: " + e.getMessage());
                }
            }
        } catch (IOException e) {
            e.printStackTrace();
        }
    }
}

class ConnectionHandler implements Runnable {
    private final Socket socket;
    private final int connectionId;
    private final AuthManager authManager;
    private final CommandDispatcher dispatcher;

    public ConnectionHandler(Socket socket, int connectionId, ExecutionEngine engine) {
        this.socket = socket;
        this.connectionId = connectionId;
        this.authManager = new AuthManager(engine);
        this.dispatcher = new CommandDispatcher(engine);
    }

    @Override
    public void run() {
        System.out.println("DEBUG: ConnectionHandler started for " + socket.getRemoteSocketAddress());
        try (InputStream in = new BufferedInputStream(socket.getInputStream());
                OutputStream out = new BufferedOutputStream(socket.getOutputStream())) {

            // 1. Send Handshake
            System.out.println("DEBUG: Sending Handshake to " + connectionId);
            String salt = "12345678901234567890";
            byte[] handshake = PacketBuilder.buildHandshake(connectionId, salt);
            MySQLPacket.writePacket(out, handshake, (byte) 0);
            System.out.println("DEBUG: Handshake sent.");

            // 2. Read Handshake Response
            byte[] response = MySQLPacket.readPacket(in);
            if (response == null) {
                System.out.println("DEBUG: Client closed connection immediately or sent empty response.");
                return;
            }
            System.out.println("DEBUG: Received Handshake Response (" + response.length + " bytes)");
            // Packet Sequence should be 1. We assume it is.

            // Parse Login Packet (Simplified)
            // Caps(4) + MaxPacket(4) + Charset(1) + Reserved(23)
            int pos = 4 + 4 + 1 + 23;

            // Username (Method: Find null byte)
            int endUser = -1;
            for (int i = pos; i < response.length; i++) {
                if (response[i] == 0) {
                    endUser = i;
                    break;
                }
            }
            if (endUser == -1) {
                close();
                return;
            }
            String user = new String(response, pos, endUser - pos, StandardCharsets.UTF_8);

            // We skip parsing auth data and DB for this phase
            // and assume AuthManager allows access.
            boolean authenticated = authManager.authenticate(user, null);

            if (!authenticated) {
                // Send Error
                MySQLPacket.writePacket(out, PacketBuilder.buildError(1045, "Access denied for user '" + user + "'"),
                        (byte) 2);
                close();
                return;
            }

            // 3. Send OK
            MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), (byte) 2);
            System.out.println("✅ User '" + user + "' authenticated.");

            // Setting Security Context for this Virtual Thread
            // Host is mocked as 'localhost' or client IP for now
            String host = socket.getInetAddress().getHostAddress(); // Or "localhost"
            com.sylo.kylo.core.security.SecurityContext.set(user, host);

            // 4. Command Phase
            while (true) {
                // Read Packet Header manually to get sequence?
                // MySQLPacket.readPacket returns payload.
                // We typically don't track sequence strictly in command phase (reset to 0
                // usually for new command)
                // Actually, the command packet starts sequence at 0.

                // Note: MySQLPacket.readPacket reads the header bytes from the stream inside.
                byte[] packet = MySQLPacket.readPacket(in);
                if (packet == null)
                    break; // EOF

                // Command Byte
                byte cmd = packet[0];
                if (cmd == CommandDispatcher.COM_QUIT) {
                    break;
                }

                // Dispatch
                // Response sequence usually starts at 1, so we pass 0 for ++seq to evaluate to 1.
                try {
                    dispatcher.dispatch(cmd, packet, out, (byte) 0);
                } catch (Exception e) {
                    MySQLPacket.writePacket(out,
                            PacketBuilder.buildError(500, "Internal Server Error: " + e.getMessage()), (byte) 1);
                }
            }

        } catch (IOException e) {
            // Client disconnect
        } finally {
            close();
            System.out.println("➖ Connection closed " + connectionId);
        }
    }

    private void close() {
        try {
            if (dispatcher != null) {
                dispatcher.close();
            }
            socket.close();
        } catch (Exception e) {
        }
    }
}
