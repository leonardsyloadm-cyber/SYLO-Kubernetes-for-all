package com.sylo.kylo.net.redis;

import com.sylo.kylo.core.execution.ExecutionEngine;
import java.io.BufferedInputStream;
import java.io.BufferedOutputStream;
import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.ServerSocket;
import java.net.Socket;

public class KyloRedisServer extends Thread {
    private final int port;
    private final ExecutionEngine engine;
    private boolean running = true;

    public KyloRedisServer(int port, ExecutionEngine engine) {
        this.port = port;
        this.engine = engine;
    }

    @Override
    public void run() {
        try (ServerSocket serverSocket = new ServerSocket(port, 50, java.net.InetAddress.getByName("0.0.0.0"))) {
            System.out.println("🔴 KyloDB Redis Interface listening on 0.0.0.0:" + port);

            // Initialize hidden Redis KV table
            RedisCommandHandler.initStorage(engine);

            while (running) {
                try {
                    Socket clientSocket = serverSocket.accept();
                    System.out.println("➕ New Redis Connection from " + clientSocket.getRemoteSocketAddress());
                    Thread.ofVirtual().start(new RedisConnectionHandler(clientSocket, engine));
                } catch (IOException e) {
                    System.err.println("Error accepting Redis connection: " + e.getMessage());
                }
            }
        } catch (IOException e) {
            e.printStackTrace();
        }
    }
}

class RedisConnectionHandler implements Runnable {
    private final Socket socket;
    private final RedisCommandHandler handler;

    public RedisConnectionHandler(Socket socket, ExecutionEngine engine) {
        this.socket = socket;
        this.handler = new RedisCommandHandler(engine);
    }

    @Override
    public void run() {
        try (InputStream in = new BufferedInputStream(socket.getInputStream());
             OutputStream out = new BufferedOutputStream(socket.getOutputStream())) {
            
            RespParser parser = new RespParser(in);
            
            while (true) {
                Object commandData = parser.readObject();
                if (commandData == null) break; // EOF or invalid
                
                if (commandData instanceof java.util.List) {
                    @SuppressWarnings("unchecked")
                    java.util.List<String> args = (java.util.List<String>) commandData;
                    if (args.isEmpty()) continue;
                    
                    String response = handler.executeCommand(args);
                    if (response != null) {
                        out.write(response.getBytes(java.nio.charset.StandardCharsets.UTF_8));
                        out.flush();
                    }
                }
            }
        } catch (Exception e) {
            // Connection closed or error
        } finally {
            try { socket.close(); } catch (Exception ignored) {}
        }
    }
}
