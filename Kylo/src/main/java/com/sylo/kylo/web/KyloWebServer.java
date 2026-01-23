package com.sylo.kylo.web;

import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpHandler;
import com.sun.net.httpserver.HttpServer;
import com.sylo.kylo.core.catalog.Catalog;
import com.sylo.kylo.core.catalog.Schema;
import com.sylo.kylo.core.execution.ExecutionEngine;

import java.io.IOException;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;
import java.util.Map;
import java.util.List;
import java.io.InputStream;
import java.io.ByteArrayOutputStream;

public class KyloWebServer {
    private HttpServer server;
    private final ExecutionEngine executionEngine;

    public KyloWebServer(ExecutionEngine executionEngine) throws IOException {
        this.executionEngine = executionEngine;
        this.server = HttpServer.create(new InetSocketAddress(8080), 0);

        // Static Content
        server.createContext("/", new StaticHandler());

        // API
        server.createContext("/api/query", new QueryHandler());
        server.createContext("/api/catalog", new CatalogHandler());
        server.createContext("/api/describe", new DescribeHandler());
        server.createContext("/api/version", new VersionHandler());

        server.setExecutor(null); // Default executor
    }

    public void start() {
        System.out.println("Sylo Architect Web Interface running on http://localhost:8080");
        server.start();
    }

    public void stop() {
        server.stop(0);
    }

    private class StaticHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange t) throws IOException {
            String path = t.getRequestURI().getPath();
            if (path.equals("/"))
                path = "/index.html";

            // Security check
            if (path.contains("..")) {
                sendResponse(t, 403, "Forbidden");
                return;
            }

            // Load from resource
            String resourcePath = "/web" + path;
            InputStream is = getClass().getResourceAsStream(resourcePath);
            if (is == null) {
                sendResponse(t, 404, "Not Found");
                return;
            }

            byte[] bytes = readAllBytes(is);
            String contentType = "text/html";
            if (path.endsWith(".css"))
                contentType = "text/css";
            else if (path.endsWith(".js"))
                contentType = "application/javascript";

            t.getResponseHeaders().set("Content-Type", contentType);
            // Disable caching to fix "stuck" app.js
            t.getResponseHeaders().set("Cache-Control", "no-store, no-cache, must-revalidate");
            t.getResponseHeaders().set("Pragma", "no-cache");
            t.getResponseHeaders().set("Expires", "0");

            t.sendResponseHeaders(200, bytes.length);
            OutputStream os = t.getResponseBody();
            os.write(bytes);
            os.close();
        }
    }

    private class VersionHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange t) throws IOException {
            String version = "1.0.0";
            sendResponse(t, 200, version);
        }
    }

    private class QueryHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange t) throws IOException {
            if (!t.getRequestMethod().equalsIgnoreCase("POST")) {
                sendResponse(t, 405, "Method Not Allowed");
                return;
            }

            String query = new String(readAllBytes(t.getRequestBody()), StandardCharsets.UTF_8);
            System.out.println("WEB QUERY: " + query);

            try {
                com.sylo.kylo.core.sql.KyloProcessor.KyloResponse res = com.sylo.kylo.core.sql.KyloProcessor
                        .process(query, executionEngine);

                StringBuilder json = new StringBuilder();
                json.append("{");
                json.append("\"success\": ").append(res.success ? "true" : "false").append(",");
                json.append("\"message\": \"").append(res.message == null ? "" : res.message.replace("\"", "\\\""))
                        .append("\",");

                json.append("\"data\": [");
                if (res.data != null && res.data instanceof List) {
                    @SuppressWarnings("unchecked")
                    List<Map<String, Object>> list = (List<Map<String, Object>>) res.data;
                    int r = 0;
                    for (Map<String, Object> map : list) {
                        if (r++ > 0)
                            json.append(",");
                        json.append("{");
                        int c = 0;
                        for (Map.Entry<String, Object> entry : map.entrySet()) {
                            if (c++ > 0)
                                json.append(",");
                            json.append("\"").append(entry.getKey()).append("\":");
                            Object val = entry.getValue();
                            if (val == null)
                                json.append("null");
                            else if (val instanceof Number || val instanceof Boolean)
                                json.append(val);
                            else
                                json.append("\"").append(val.toString().replace("\"", "\\\"")).append("\"");
                        }
                        json.append("}");
                    }
                }
                json.append("]");
                json.append("}");

                t.getResponseHeaders().set("Content-Type", "application/json");
                sendResponse(t, 200, json.toString());
            } catch (Exception e) {
                e.printStackTrace();
                sendResponse(t, 500, "{\"success\":false, \"message\":\"" + e.getMessage() + "\"}");
            }
        }
    }

    private class CatalogHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange t) throws IOException {
            // Return Nested Tree structure: { "DBName": { "Table": [ "Col:Type", ... ] },
            // ... }
            Catalog cat = Catalog.getInstance();
            StringBuilder json = new StringBuilder("{");

            boolean firstBin = true;
            for (String dbName : cat.getDatabases()) {

                if (!firstBin)
                    json.append(",");
                firstBin = false;

                json.append("\"").append(dbName).append("\": {");

                // Filter tables for this DB
                boolean firstT = true;
                for (String fullTableName : cat.getAllTableNames()) {
                    // Check if table belongs to this DB
                    // Format is usually "DB:Table" or just "Table" (assumed Default)
                    String tDb = "Default";
                    String tName = fullTableName;
                    if (fullTableName.contains(":")) {
                        String[] parts = fullTableName.split(":");
                        tDb = parts[0];
                        tName = parts[1];
                    }

                    if (tDb.equalsIgnoreCase(dbName)) {
                        if (!firstT)
                            json.append(",");
                        firstT = false;

                        Schema schema = cat.getTableSchema(fullTableName);
                        json.append("\"").append(tName).append("\": [");

                        for (int i = 0; i < schema.getColumnCount(); i++) {
                            if (i > 0)
                                json.append(",");
                            json.append("\"").append(schema.getColumn(i).getName()).append(":")
                                    .append(schema.getColumn(i).getType()).append("\"");
                        }
                        json.append("]");
                    }
                }
                json.append("}");
            }
            json.append("}");

            t.getResponseHeaders().set("Content-Type", "application/json");
            sendResponse(t, 200, json.toString());
        }
    }

    private class DescribeHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange t) throws IOException {
            // Path: /api/describe/{db}/{tbl}
            String path = t.getRequestURI().getPath();
            String[] parts = path.split("/");
            // parts[0]="", parts[1]="api", parts[2]="describe", parts[3]=db, parts[4]=tbl

            if (parts.length < 5) {
                sendResponse(t, 400, "[]");
                return;
            }

            // String db = parts[3]; // Not used yet, assuming Default
            String tbl = parts[4];

            Catalog cat = Catalog.getInstance();
            Schema schema = cat.getTableSchema(tbl);

            StringBuilder json = new StringBuilder("[");
            if (schema != null) {
                for (int i = 0; i < schema.getColumnCount(); i++) {
                    if (i > 0)
                        json.append(",");
                    json.append("{");
                    json.append("\"name\":\"").append(schema.getColumn(i).getName()).append("\",");
                    json.append("\"type\":\"").append(schema.getColumn(i).getType()).append("\"");
                    json.append("}");
                }
            }
            json.append("]");

            t.getResponseHeaders().set("Content-Type", "application/json");
            sendResponse(t, 200, json.toString());
        }
    }

    private void sendResponse(HttpExchange t, int code, String response) throws IOException {
        byte[] bytes = response.getBytes(StandardCharsets.UTF_8);
        t.sendResponseHeaders(code, bytes.length);
        OutputStream os = t.getResponseBody();
        os.write(bytes);
        os.close();
    }

    private byte[] readAllBytes(InputStream is) throws IOException {
        ByteArrayOutputStream buffer = new ByteArrayOutputStream();
        int nRead;
        byte[] data = new byte[1024];
        while ((nRead = is.read(data, 0, data.length)) != -1) {
            buffer.write(data, 0, nRead);
        }
        buffer.flush();
        return buffer.toByteArray();
    }
}
