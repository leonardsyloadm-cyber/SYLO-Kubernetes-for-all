package com.sylo.kylo;

import java.util.*;

import com.sylo.kylo.web.KyloWebServer;
import com.sylo.kylo.core.execution.ExecutionEngine;

public class KyloServer {

    private static final Set<String> SYSTEM_DBS = Collections.synchronizedSet(new HashSet<>());
    private static ExecutionEngine engine;
    private static KyloWebServer webServer;

    public static void main(String[] args) {
        System.out.println("💎 KyloDB v32 Turbo-Core & SQL Brain Online...");

        // Init Engine
        engine = new ExecutionEngine("kylo_storage.db");

        // Init default DB
        if (!SYSTEM_DBS.contains("default")) {
            SYSTEM_DBS.add("default");
        }

        // Start Bootstrapper for Security
        new com.sylo.kylo.core.security.SystemBootstrapper(engine).bootstrap();

        // Start Operation Impostor (MySQL Layer)
        new com.sylo.kylo.net.KyloProtocolServer(3307, engine).start();

        // Start Sylo Architect (Web Server)
        try {
            webServer = new KyloWebServer(engine);
            webServer.start();
        } catch (java.io.IOException e) {
            System.err.println("Failed to start Web Server: " + e.getMessage());
        }

        // Keep alive
        Runtime.getRuntime().addShutdownHook(new Thread(() -> {
            System.out.println("Shutting down KyloDB...");
            if (webServer != null)
                webServer.stop();
            engine.close();
        }));
    }

    // Static method used by WebServer or internal calls if needed.
    // Ideally WebServer calls Engine directly, but for 'KyloQL' custom syntax (like
    // CREATE DATABASE),
    // we might need a parser/handler layer.
    // For this implementation, I'll move executeKyloQL logic to a helper or let
    // WebServer use it?
    // WebServer currently mocks response. I should update WebServer to use this
    // logic?
    // Or update WebServer to call this logic?
    // Since WebServer is in another package, I'll make this public static or move
    // logic to Engine?
    // Engine deals with physical execution. This is "SQL Command Processor".
    // I will leave it here and let WebServer call it if I update WebServer, OR
    // I will act as a "Session" handler.
    // For now, let's keep it here but I need to link WebServer to it?
    // The previous WebServer code mocked execution.
    // I should update KyloWebServer to delegate to basic execution.
}