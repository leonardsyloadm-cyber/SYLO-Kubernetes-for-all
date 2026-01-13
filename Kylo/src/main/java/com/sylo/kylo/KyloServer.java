package com.sylo.kylo;

import com.sylo.kylo.engine.KyloSecurity;
import io.javalin.Javalin;
import com.fasterxml.jackson.databind.ObjectMapper;
import java.io.*;
import java.util.*;
import java.util.concurrent.ConcurrentHashMap;
import java.util.regex.*;

public class KyloServer {

    private static final ConcurrentHashMap<String, String> MEM_TABLE = new ConcurrentHashMap<>();
    private static final ConcurrentHashMap<String, String> SCHEMA_TABLE = new ConcurrentHashMap<>();
    private static final Set<String> SYSTEM_DBS = Collections.synchronizedSet(new HashSet<>());
    private static final String DB_FILE = "kylo_secure.db";
    private static String currentDB = "default";
    
    // Regex mejorado para capturar columnas y valores por separado
    private static final Pattern CREATE_PATTERN = Pattern.compile("\\((.*?)\\)"); 
    private static final Pattern INSERT_PATTERN = Pattern.compile("INSERT\\s+INTO\\s+(\\w+)\\s*(?:\\((.*?)\\))?\\s*VALUES\\s*\\((.*?)\\)", Pattern.CASE_INSENSITIVE);

    public static void main(String[] args) {
        System.out.println("💎 KyloDB v28 Smart Insert...");
        loadFromDisk();
        if (!SYSTEM_DBS.contains("default")) { SYSTEM_DBS.add("default"); persist("SYS:CREATE_DB:default"); }

        Javalin app = Javalin.create(config -> config.staticFiles.add("/web")).start(8080);

        // API CATALOG
        app.get("/api/catalog", ctx -> {
            Map<String, Map<String, List<String>>> deepTree = new HashMap<>();
            SYSTEM_DBS.forEach(db -> {
                Map<String, List<String>> tables = new HashMap<>();
                SCHEMA_TABLE.keySet().stream().filter(k -> k.startsWith(db + ":")).forEach(sk -> {
                    String tName = sk.split(":")[1];
                    List<String> cols = Arrays.asList(SCHEMA_TABLE.get(sk).split(","));
                    tables.put(tName, cols);
                });
                deepTree.put(db, tables);
            });
            ctx.json(deepTree);
        });

        // API DESCRIBE
        app.get("/api/describe/{db}/{table}", ctx -> {
            String s = SCHEMA_TABLE.get(ctx.pathParam("db") + ":" + ctx.pathParam("table"));
            if(s==null){ctx.status(404);return;}
            List<Map<String,String>> cols = new ArrayList<>();
            for(String c : s.split(",")) {
                String[] p = c.split(":");
                cols.add(Map.of("name", p[0], "type", p[1]));
            }
            ctx.json(cols);
        });

        app.post("/api/query", ctx -> {
            KyloResponse res = new KyloResponse();
            try { executeKyloQL(ctx.body().trim(), res); res.success = true; } 
            catch (Exception e) { res.success = false; res.message = e.getMessage(); }
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
                    SYSTEM_DBS.add(db); persist("SYS:CREATE_DB:" + db); res.message = "DB Creada.";
                } 
                else if (parts[1].equalsIgnoreCase("TABLE")) {
                    String tbl = parts[2].split("\\(")[0];
                    Matcher m = CREATE_PATTERN.matcher(q);
                    if(!m.find()) throw new Exception("Error: Esquema inválido.");
                    StringBuilder schema = new StringBuilder();
                    for(String col : m.group(1).split(",")) {
                        String[] tokens = col.trim().split("\\s+");
                        if(schema.length()>0) schema.append(",");
                        // Guardar nombre:tipo:constraints
                        schema.append(tokens[0]).append(":").append(tokens[1].toUpperCase());
                        if(col.toUpperCase().contains("PRIMARY KEY")) schema.append(":PK");
                        if(col.toUpperCase().contains("UNIQUE")) schema.append(":UQ");
                    }
                    String k = currentDB + ":" + tbl;
                    SCHEMA_TABLE.put(k, schema.toString()); persist("SYS:SCHEMA:" + k + ":" + schema); res.message = "Tabla Creada.";
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
                
                String schemaStr = SCHEMA_TABLE.get(currentDB + ":" + tName);
                if(schemaStr == null) throw new Exception("Tabla no existe.");
                
                String[] schemaCols = schemaStr.split(","); // [id:INT:PK, nombre:TEXT]
                Map<String, String> insertMap = new HashMap<>();
                
                // Parsear valores
                List<String> values = new ArrayList<>();
                Matcher vm = Pattern.compile("'([^']*)'|([^,]+)").matcher(valsDef);
                while(vm.find()) values.add(vm.group(1)!=null ? vm.group(1) : vm.group(2).trim());

                // Mapear Columnas -> Valores
                if(colsDef != null && !colsDef.isBlank()) {
                    String[] specifiedCols = colsDef.split(",");
                    if(specifiedCols.length != values.size()) throw new Exception("Num columnas != Num valores");
                    for(int i=0; i<specifiedCols.length; i++) {
                        insertMap.put(specifiedCols[i].trim(), values.get(i));
                    }
                } else {
                    // Si no especifica columnas, asume orden del esquema
                    if(values.size() != schemaCols.length) throw new Exception("Valores no coinciden con esquema.");
                    for(int i=0; i<schemaCols.length; i++) {
                        insertMap.put(schemaCols[i].split(":")[0], values.get(i));
                    }
                }

                // Construir JSON final ordenado según esquema y validar tipos
                StringBuilder json = new StringBuilder("{");
                for(int i=0; i<schemaCols.length; i++) {
                    String[] def = schemaCols[i].split(":"); // 0=nombre, 1=tipo
                    String colName = def[0];
                    String colType = def[1];
                    String val = insertMap.getOrDefault(colName, "NULL"); // Valor o NULL si falta
                    
                    // Validación de Tipos
                    if(colType.equals("INT") && !val.equals("NULL") && !val.matches("-?\\d+")) 
                        throw new Exception("Error Tipo INT en columna: " + colName);
                    
                    json.append("\"").append(colName).append("\":\"").append(val).append("\"");
                    if(i < schemaCols.length-1) json.append(",");
                }
                json.append("}");
                
                String id = UUID.randomUUID().toString().substring(0,8);
                String rKey = currentDB + ":" + tName + ":" + id;
                MEM_TABLE.put(rKey, json.toString());
                persist("SET:" + rKey + ":" + json);
                res.message = "Insertado.";
                break;

            case "SELECT": 
                String st = parts[3]; // Tabla
                String pre = currentDB + ":" + st + ":";
                ObjectMapper om = new ObjectMapper();
                List<Map<String,Object>> l = new ArrayList<>();
                // Filtro WHERE básico (simulado)
                String whereCol = null, whereOp = null, whereVal = null;
                if(q.contains("WHERE")) {
                    String[] wParts = q.split("WHERE")[1].trim().split("\\s+");
                    if(wParts.length >= 3) { whereCol=wParts[0]; whereOp=wParts[1]; whereVal=wParts[2].replace("'",""); }
                }

                for(Map.Entry<String,String> e : MEM_TABLE.entrySet()) {
                    if(e.getKey().startsWith(pre)) {
                        try {
                            Map<String,Object> m = om.readValue(e.getValue(), Map.class);
                            m.put("_ID", e.getKey().split(":")[2]);
                            
                            // Lógica de filtrado
                            if(whereCol != null) {
                                String val = String.valueOf(m.get(whereCol));
                                if(whereOp.equals("=") && !val.equals(whereVal)) continue;
                                if(whereOp.equals("!=") && val.equals(whereVal)) continue;
                                // Mas operadores simplificados...
                            }
                            l.add(m);
                        }catch(Exception x){}
                    }
                }
                res.data = l; res.message = "Filas: " + l.size();
                break;

            case "DELETE":
                if(q.contains("WHERE _ID=")) {
                    String did = q.split("_ID=")[1].replace("'", "").trim();
                    String dk = currentDB + ":" + parts[2] + ":" + did;
                    if(MEM_TABLE.remove(dk)!=null) { persist("DEL:"+dk); res.message="Borrado."; }
                    else throw new Exception("ID no encontrado.");
                } else if(q.contains("WHERE")) {
                     res.message = "DELETE masivo no implementado por seguridad. Usa _ID.";
                }
                break;
                
            case "TRUNCATE":
                 String tr = parts[2];
                 MEM_TABLE.keySet().removeIf(k->k.startsWith(currentDB+":"+tr+":"));
                 res.message="Tabla vaciada.";
                 break;

            case "DROP":
                if(parts[1].equalsIgnoreCase("TABLE")) {
                    String t = parts[2];
                    MEM_TABLE.keySet().removeIf(k->k.startsWith(currentDB+":"+t+":"));
                    SCHEMA_TABLE.remove(currentDB+":"+t); persist("SYS:DROP_TBL:"+currentDB+":"+t);
                    res.message="Tabla eliminada.";
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
    private static void persist(String r) { try{appendLog(KyloSecurity.encrypt(r));}catch(Exception e){} }
    private static void appendLog(String l) { try(PrintWriter w=new PrintWriter(new FileWriter(DB_FILE,true))){w.println(l);}catch(IOException e){} }
    private static void loadFromDisk() {
        File f = new File(DB_FILE); if(!f.exists()) return;
        try(BufferedReader br = new BufferedReader(new FileReader(f))) {
            String l;
            while((l=br.readLine())!=null) {
                try {
                    String d = KyloSecurity.decrypt(l);
                    if(d.startsWith("SYS:CREATE_DB")) SYSTEM_DBS.add(d.split(":")[2]);
                    else if(d.startsWith("SYS:SCHEMA")) { String[] p=d.split(":",4); SCHEMA_TABLE.put(p[2]+":"+p[3], p[4]); }
                    else if(d.startsWith("SET:")) { String[] p=d.split(":",3); MEM_TABLE.put(p[1], p[2]); }
                    else if(d.startsWith("DEL:")) { MEM_TABLE.remove(d.split(":",2)[1]); }
                }catch(Exception e){}
            }
        }catch(Exception e){}
    }
}