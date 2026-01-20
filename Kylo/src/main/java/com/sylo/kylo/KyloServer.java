package com.sylo.kylo;

import com.sylo.kylo.engine.KyloSecurity;
import io.javalin.Javalin;
import com.fasterxml.jackson.databind.ObjectMapper;
import java.io.*;
import java.util.*;
import java.util.concurrent.ConcurrentHashMap;
import java.util.regex.*;

public class KyloServer {

    // private static final ConcurrentHashMap<String, String> MEM_TABLE = new ConcurrentHashMap<>(); // Legacy
    // private static final ConcurrentHashMap<String, String> SCHEMA_TABLE = new ConcurrentHashMap<>(); // Legacy
    // Keeping SYSTEM_DBS for DB names management for now
    private static final Set<String> SYSTEM_DBS = Collections.synchronizedSet(new HashSet<>());
    // private static final String DB_FILE = "kylo_secure.db"; // Legacy Log
    
    private static com.sylo.kylo.core.execution.ExecutionEngine engine;
    private static String currentDB = "default";
    
    // Regex mejorado para capturar columnas y valores por separado
    private static final Pattern CREATE_PATTERN = Pattern.compile("\\((.*?)\\)"); 
    private static final Pattern INSERT_PATTERN = Pattern.compile("INSERT\\s+INTO\\s+(\\w+)\\s*(?:\\((.*?)\\))?\\s*VALUES\\s*\\((.*?)\\)", Pattern.CASE_INSENSITIVE);

    public static void main(String[] args) {
        System.out.println("💎 KyloDB v30 Enterprise Core (Phase 1)...");
        // Init Engine
        engine = new com.sylo.kylo.core.execution.ExecutionEngine("kylo_storage.db");
        // Init default DB
        // Init default DB
        if (!SYSTEM_DBS.contains("default")) { SYSTEM_DBS.add("default"); }

        // Start Operation Impostor (MySQL Layer)
        new com.sylo.kylo.net.KyloProtocolServer(3307, engine).start();

        Javalin app = Javalin.create(config -> config.staticFiles.add("/web")).start(8080);

        // API CATALOG
        app.get("/api/catalog", ctx -> {
            Map<String, Map<String, List<String>>> deepTree = new HashMap<>();
            
            Map<String, com.sylo.kylo.core.catalog.Schema> allTables = com.sylo.kylo.core.catalog.Catalog.getInstance().getTables();
            
            // Reconstruct Tree structure from "db:table" keys
            for (String key : allTables.keySet()) {
                String[] parts = key.split(":");
                String db = parts.length > 1 ? parts[0] : "default";
                String tb = parts.length > 1 ? parts[1] : key;
                
                deepTree.putIfAbsent(db, new HashMap<>());
                
                List<String> colNames = new ArrayList<>();
                com.sylo.kylo.core.catalog.Schema s = allTables.get(key);
                for(int i=0; i<s.getColumnCount(); i++) colNames.add(s.getColumn(i).getName() + ":" + s.getColumn(i).getType().getClass().getSimpleName());
                
                deepTree.get(db).put(tb, colNames);
            }
            // Ensure system DBs exist in tree even if empty
            SYSTEM_DBS.forEach(db -> deepTree.putIfAbsent(db, new HashMap<>()));
            
            ctx.json(deepTree);
        });

        // API DESCRIBE
        app.get("/api/describe/{db}/{table}", ctx -> {
            String full = ctx.pathParam("db") + ":" + ctx.pathParam("table");
            com.sylo.kylo.core.catalog.Schema s = com.sylo.kylo.core.catalog.Catalog.getInstance().getTableSchema(full);
            if(s==null){ctx.status(404);return;}
            
            List<Map<String,String>> cols = new ArrayList<>();
            for(int i=0; i<s.getColumnCount(); i++) {
                cols.add(Map.of("name", s.getColumn(i).getName(), "type", s.getColumn(i).getType().getClass().getSimpleName()));
            }
            ctx.json(cols);
        });

        app.post("/api/query", ctx -> {
            // Reopen engine if closed? No, keep open.
            KyloResponse res = new KyloResponse();
             try { executeKyloQL(ctx.body().trim(), res); res.success = true; } 
             catch (Exception e) { e.printStackTrace(); res.success = false; res.message = e.getMessage(); }
             ctx.json(res);
        });
    }

    private static void executeKyloQL(String query, KyloResponse res) throws Exception {
        String q = query.replace(";", "").trim();
        String[] parts = q.split("\\s+");
        String verb = parts[0].toUpperCase();

        switch (verb) {
            case "CREATE":
                if (parts[1].equalsIgnoreCase("DATABASE")) {
                    String db = parts[2];
                    if (SYSTEM_DBS.contains(db)) throw new Exception("Error: DB ya existe.");
                    SYSTEM_DBS.add(db); res.message = "DB Creada.";
                } 
                else if (parts[1].equalsIgnoreCase("TABLE")) {
                    String tbl = parts[2].split("\\(")[0];
                    Matcher m = CREATE_PATTERN.matcher(q);
                    if(!m.find()) throw new Exception("Error: Esquema inválido.");
                    
                    List<com.sylo.kylo.core.catalog.Column> columns = new ArrayList<>();
                    
                    for(String col : m.group(1).split(",")) {
                        String[] tokens = col.trim().split("\\s+");
                        String colName = tokens[0];
                        String typeStr = tokens[1].toUpperCase();
                        
                        com.sylo.kylo.core.structure.KyloType type = null;
                        if(typeStr.contains("INT")) type = new com.sylo.kylo.core.structure.KyloInt();
                        else if(typeStr.contains("BIGINT")) type = new com.sylo.kylo.core.structure.KyloBigInt();
                        else if(typeStr.contains("TEXT") || typeStr.contains("VARCHAR")) type = new com.sylo.kylo.core.structure.KyloVarchar(255);
                        else if(typeStr.contains("BOOLEAN")) type = new com.sylo.kylo.core.structure.KyloBoolean();
                        else if(typeStr.contains("TIMESTAMP")) type = new com.sylo.kylo.core.structure.KyloTimestamp();
                        else if(typeStr.contains("FlOAT")) type = new com.sylo.kylo.core.structure.KyloFloat();
                        else if(typeStr.contains("DOUBLE")) type = new com.sylo.kylo.core.structure.KyloDouble();
                        else if(typeStr.contains("UUID")) type = new com.sylo.kylo.core.structure.KyloUuid();
                        else if(typeStr.contains("BLOB")) type = new com.sylo.kylo.core.structure.KyloBlob();
                        else if(typeStr.contains("DATETIME")) type = new com.sylo.kylo.core.structure.KyloDateTime();
                        else if(typeStr.contains("DATE")) type = new com.sylo.kylo.core.structure.KyloDate();
                        else if(typeStr.contains("TIME")) type = new com.sylo.kylo.core.structure.KyloTime();
                        else type = new com.sylo.kylo.core.structure.KyloVarchar(50); // Fallback
                        
                        columns.add(new com.sylo.kylo.core.catalog.Column(colName, type, false));
                    }
                    
                    String k = currentDB + ":" + tbl;
                    com.sylo.kylo.core.catalog.Schema schema = new com.sylo.kylo.core.catalog.Schema(columns);
                    com.sylo.kylo.core.catalog.Catalog.getInstance().createTable(k, schema);
                    
                    res.message = "Tabla Creada (Storage Engine).";
                }
                break;

            case "USE":
                if(!SYSTEM_DBS.contains(parts[1])) throw new Exception("Error: DB no existe.");
                currentDB = parts[1]; res.message = "Usando " + currentDB;
                break;

            case "INSERT":
                Matcher mIns = INSERT_PATTERN.matcher(q);
                if(!mIns.find()) throw new Exception("Sintaxis INSERT inválida.");
                
                String tName = mIns.group(1);
                String colsDef = mIns.group(2); // Puede ser null si no especifican columnas
                String valsDef = mIns.group(3);
                
                String fullTableName = currentDB + ":" + tName;
                com.sylo.kylo.core.catalog.Schema schema = com.sylo.kylo.core.catalog.Catalog.getInstance().getTableSchema(fullTableName);
                if(schema == null) throw new Exception("Tabla no existe en Catalog.");
                
                // Parse values matches
                List<String> rawValues = new ArrayList<>();
                Matcher vm = Pattern.compile("'([^']*)'|([^,]+)").matcher(valsDef);
                while(vm.find()) rawValues.add(vm.group(1)!=null ? vm.group(1) : vm.group(2).trim());

                // Construct Object array for Tuple
                Object[] tupleData = new Object[schema.getColumnCount()];
                
                // Assume order matches schema for simplicity in this integration
                if(rawValues.size() != schema.getColumnCount()) throw new Exception("Column count mismatch");
                
                for(int i=0; i<schema.getColumnCount(); i++) {
                    com.sylo.kylo.core.catalog.Column col = schema.getColumn(i);
                    String raw = rawValues.get(i);
                    Object val = null;
                    
                    if(raw.equalsIgnoreCase("NULL")) val = null;
                    else if(col.getType() instanceof com.sylo.kylo.core.structure.KyloInt) val = Integer.parseInt(raw);
                    else if(col.getType() instanceof com.sylo.kylo.core.structure.KyloBigInt) val = Long.parseLong(raw);
                    else if(col.getType() instanceof com.sylo.kylo.core.structure.KyloDouble) val = Double.parseDouble(raw);
                    else if(col.getType() instanceof com.sylo.kylo.core.structure.KyloFloat) val = Float.parseFloat(raw);
                    else if(col.getType() instanceof com.sylo.kylo.core.structure.KyloBoolean) val = Boolean.parseBoolean(raw);
                    else if(col.getType() instanceof com.sylo.kylo.core.structure.KyloTimestamp) {
                        try { val = java.time.Instant.parse(raw); } catch(Exception e) { val = java.time.Instant.now(); } // Fallback/Support ISO
                    }
                    else if(col.getType() instanceof com.sylo.kylo.core.structure.KyloDate) val = java.time.LocalDate.parse(raw);
                    else if(col.getType() instanceof com.sylo.kylo.core.structure.KyloTime) val = java.time.LocalTime.parse(raw);
                    else if(col.getType() instanceof com.sylo.kylo.core.structure.KyloDateTime) val = java.time.LocalDateTime.parse(raw);
                    else if(col.getType() instanceof com.sylo.kylo.core.structure.KyloUuid) val = java.util.UUID.fromString(raw);
                    else if(col.getType() instanceof com.sylo.kylo.core.structure.KyloBlob) val = raw.getBytes(java.nio.charset.StandardCharsets.UTF_8);
                    else val = raw; // Strings
                    
                    tupleData[i] = val;
                }
                
                engine.insertTuple(fullTableName, tupleData);
                res.message = "Insertado en Disk Page.";
                break;

            case "SELECT": 
                String st = parts[3]; // Tabla
                String fullT = currentDB + ":" + st;
                
                List<Object[]> rows = engine.scanTable(fullT);
                com.sylo.kylo.core.catalog.Schema s = com.sylo.kylo.core.catalog.Catalog.getInstance().getTableSchema(fullT);
                if(s==null) throw new Exception("Tabla no encontrada");
                
                List<Map<String,Object>> l = new ArrayList<>();
                for(Object[] r : rows) {
                    Map<String,Object> m = new HashMap<>();
                    for(int i=0; i<s.getColumnCount(); i++) {
                        m.put(s.getColumn(i).getName(), r[i]);
                    }
                    l.add(m);
                }
                res.data = l; res.message = "Filas leídas de Disco: " + l.size();
                break;

            case "DELETE":
                res.message = "DELETE no soportado en Phase 1 (Core Engine Append-Only).";
                break;
                
            case "TRUNCATE":
                // com.sylo.kylo.core.catalog.Catalog.getInstance().removeTable... and recreate?
                // For now stub.
                res.message = "TRUNCATE no soportado en Phase 1.";
                break;

            case "DROP":
                if(parts[1].equalsIgnoreCase("TABLE")) {
                    String t = parts[2];
                    String k = currentDB + ":" + t;
                    com.sylo.kylo.core.catalog.Catalog.getInstance().removeTable(k);
                    res.message="Tabla eliminada (Metadatos).";
                }
                break;

            default: 
                // Comandos fake para la UI
                if(verb.equals("UPDATE") || verb.equals("BACKUP") || verb.equals("OPTIMIZE")) 
                    res.message = "Comando ejecutado (Simulación).";
                else 
                    throw new Exception("Comando desconocido: " + verb);
        }
    }
    
    static class KyloResponse { public boolean success; public String message; public Object data; }
    // private static void persist(String r) { try{appendLog(KyloSecurity.encrypt(r));}catch(Exception e){} }
    // private static void appendLog(String l) { try(PrintWriter w=new PrintWriter(new FileWriter(DB_FILE,true))){w.println(l);}catch(IOException e){} }
    private static void loadFromDisk() {
        // No-op in new engine for this file
    }
}