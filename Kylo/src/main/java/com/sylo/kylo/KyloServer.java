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
        // Check if we are in Docker (Volume mounted at /app/kylo_storage)
        java.io.File dockerDataDir = new java.io.File("/app/kylo_storage");
        java.io.File dataDir;
        java.io.File dbFile;

        if (dockerDataDir.exists() && dockerDataDir.isDirectory()) {
            System.out.println("🐳 Docker environment detected. Using volume storage.");
            dataDir = dockerDataDir;
            dbFile = new java.io.File(dataDir, "kylo_storage.db");
        } else {
            System.out.println("💻 Local environment detected. Using local storage.");
            dataDir = new java.io.File("kylo_system/data");
            if (!dataDir.exists())
                dataDir.mkdirs();
            dbFile = new java.io.File(dataDir, "kylo_storage.db");
        }

        if (!dbFile.exists()) {
            System.out.println("⚠️ Fresh DB detected. Wiping stale metadata to prevent corruption...");
            new java.io.File("kylo_system/indexes/index_roots.dat").delete();
            new java.io.File("kylo_system/indexes/index_names.dat").delete();
            new java.io.File("kylo_system/indexes/foreign_keys.dat").delete();
            new java.io.File("kylo_system/settings/indexes.dat").delete();
            new java.io.File("kylo_system/settings/constraints.dat").delete();
            new java.io.File("kylo_system/views/views.dat").delete();
        }

        engine = new ExecutionEngine(dbFile.getAbsolutePath());

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

        System.out.println("🚀 KyloDB is READY and Waiting for connections...");
        try {
            Thread.currentThread().join();
        } catch (InterruptedException e) {
            e.printStackTrace();
        }
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