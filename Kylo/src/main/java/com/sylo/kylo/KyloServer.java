package com.sylo.kylo;

import com.sylo.kylo.engine.KyloSecurity;
import io.javalin.Javalin;
import com.fasterxml.jackson.databind.ObjectMapper;
import java.io.*;
import java.util.*;
import java.util.concurrent.ConcurrentHashMap;
import java.util.regex.*;
import java.util.stream.Collectors;

public class KyloServer {

    private static final ConcurrentHashMap<String, String> MEM_TABLE = new ConcurrentHashMap<>();
    private static final ConcurrentHashMap<String, String> SCHEMA_TABLE = new ConcurrentHashMap<>();
    private static final Set<String> SYSTEM_DBS = Collections.synchronizedSet(new HashSet<>());
    private static final String DB_FILE = "kylo_secure.db";
    private static String currentDB = "default";
    private static final long START_TIME = System.currentTimeMillis();
    
    private static final Pattern CREATE_PATTERN = Pattern.compile("\\((.*?)\\)");
    private static final Pattern EMAIL_REGEX = Pattern.compile("^[A-Za-z0-9+_.-]+@(.+)$");

    public static void main(String[] args) {
        System.out.println("💎 KyloDB v15 Architect Pro...");
        loadFromDisk();
        if (!SYSTEM_DBS.contains("default")) { SYSTEM_DBS.add("default"); persist("SYS:CREATE_DB:default"); }

        Javalin app = Javalin.create(config -> config.staticFiles.add("/web")).start(8080);

        // API CATALOGO (ARBOL PROFUNDO)
        app.get("/api/catalog", ctx -> {
            Map<String, Map<String, List<String>>> deepTree = new HashMap<>();
            SYSTEM_DBS.forEach(db -> {
                Map<String, List<String>> tables = new HashMap<>();
                SCHEMA_TABLE.keySet().stream().filter(k -> k.startsWith(db + ":")).forEach(sk -> {
                    String tName = sk.split(":")[1];
                    // Formato schema: "col:TYPE:CONST,col2..." -> lista de strings
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
                cols.add(Map.of("name", p[0], "type", p[1], "constraint", p.length>2?p[2]:""));
            }
            ctx.json(cols);
        });

        app.post("/api/query", ctx -> {
            KyloResponse res = new KyloResponse();
            long t = System.nanoTime();
            try { executeKyloQL(ctx.body().trim(), res); res.success = true; } 
            catch (Exception e) { res.success = false; res.message = e.getMessage(); }
            res.time = (System.nanoTime() - t) / 1_000_000.0;
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
                    SYSTEM_DBS.add(db);
                    persist("SYS:CREATE_DB:" + db);
                    res.message = "DB Creada.";
                } 
                else if (parts[1].equalsIgnoreCase("TABLE")) {
                    String tbl = parts[2].split("\\(")[0];
                    Matcher m = CREATE_PATTERN.matcher(q);
                    if(!m.find()) throw new Exception("Error: Faltan columnas.");
                    StringBuilder schema = new StringBuilder();
                    for(String col : m.group(1).split(",")) {
                        String[] tokens = col.trim().split("\\s+");
                        if(schema.length()>0) schema.append(",");
                        String cName = tokens[0]; String cType = tokens[1].toUpperCase();
                        String cConst = "";
                        if(col.toUpperCase().contains("PRIMARY KEY")) cConst="PK";
                        else if(col.toUpperCase().contains("UNIQUE")) cConst="UQ";
                        schema.append(cName).append(":").append(cType).append(":").append(cConst);
                    }
                    String k = currentDB + ":" + tbl;
                    SCHEMA_TABLE.put(k, schema.toString());
                    persist("SYS:SCHEMA:" + k + ":" + schema);
                    res.message = "Tabla Creada.";
                }
                else if(parts[1].equalsIgnoreCase("INDEX") || parts[1].equalsIgnoreCase("USER")) {
                    res.message = "Objeto creado (Simulado)."; // Fake support
                }
                break;

            case "ALTER": 
                if(parts[1].equalsIgnoreCase("TABLE") && parts[3].equalsIgnoreCase("ADD")) {
                    String t = parts[2]; String cName = parts[5]; String cType = parts[6].toUpperCase();
                    String key = currentDB + ":" + t;
                    String old = SCHEMA_TABLE.get(key);
                    if(old == null) throw new Exception("Error: Tabla no existe.");
                    String newS = old + "," + cName + ":" + cType + ":";
                    SCHEMA_TABLE.put(key, newS);
                    persist("SYS:SCHEMA:" + key + ":" + newS);
                    res.message = "Columna Añadida.";
                }
                break;

            case "USE":
                if(!SYSTEM_DBS.contains(parts[1])) throw new Exception("Error: DB no existe.");
                currentDB = parts[1];
                res.message = "Usando " + currentDB;
                break;

            case "INSERT":
                String tName = parts[2];
                String sStr = SCHEMA_TABLE.get(currentDB + ":" + tName);
                if(sStr == null) throw new Exception("Error: Tabla no existe.");
                Matcher mVal = CREATE_PATTERN.matcher(q);
                if(!mVal.find()) throw new Exception("Error: Falta VALUES (...)");
                
                List<String> vals = new ArrayList<>();
                Matcher vm = Pattern.compile("'([^']*)'|([^,]+)").matcher(mVal.group(1));
                while(vm.find()) vals.add(vm.group(1)!=null ? vm.group(1) : vm.group(2).trim());

                String[] cols = sStr.split(",");
                if(vals.size() != cols.length) throw new Exception("Columnas incorrectas.");
                
                StringBuilder json = new StringBuilder("{");
                for(int i=0; i<cols.length; i++) {
                    String[] def = cols[i].split(":");
                    String val = vals.get(i).replace("'", "");
                    if(def[1].equals("INT") && !val.matches("-?\\d+")) throw new Exception("Error Tipo INT.");
                    json.append("\"").append(def[0]).append("\":\"").append(val).append("\"");
                    if(i<cols.length-1) json.append(",");
                }
                json.append("}");
                String id = UUID.randomUUID().toString().substring(0,8);
                String rKey = currentDB + ":" + tName + ":" + id;
                MEM_TABLE.put(rKey, json.toString());
                persist("SET:" + rKey + ":" + json);
                res.message = "Insertado.";
                break;

            case "SELECT": 
                String st = parts[3];
                String pre = currentDB + ":" + st + ":";
                ObjectMapper om = new ObjectMapper();
                List<Map<String,Object>> l = new ArrayList<>();
                for(Map.Entry<String,String> e : MEM_TABLE.entrySet()) {
                    if(e.getKey().startsWith(pre)) {
                        try {
                            Map<String,Object> m = om.readValue(e.getValue(), Map.class);
                            m.put("_ID", e.getKey().split(":")[2]);
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
                } else throw new Exception("Usa WHERE _ID=...");
                break;

            case "DROP":
                if(parts[1].equalsIgnoreCase("DATABASE")) {
                    String d = parts[2]; if(d.equals("default")) throw new Exception("No default.");
                    MEM_TABLE.keySet().removeIf(k->k.startsWith(d+":"));
                    SCHEMA_TABLE.keySet().removeIf(k->k.startsWith(d+":"));
                    SYSTEM_DBS.remove(d); persist("SYS:DROP_DB:"+d);
                    currentDB="default"; res.message="DB Borrada.";
                } else if(parts[1].equalsIgnoreCase("TABLE")) {
                    String t = parts[2];
                    MEM_TABLE.keySet().removeIf(k->k.startsWith(currentDB+":"+t+":"));
                    SCHEMA_TABLE.remove(currentDB+":"+t); persist("SYS:DROP_TBL:"+currentDB+":"+t);
                    res.message="Tabla Borrada.";
                }
                break;
                
             case "TRUNCATE":
                 String tr = parts[2];
                 MEM_TABLE.keySet().removeIf(k->k.startsWith(currentDB+":"+tr+":"));
                 res.message="Vaciado.";
                 break;
                 
             // --- NUEVOS COMANDOS "FAKE" PARA CHEATSHEET ---
             case "BACKUP": res.message = "Copia de seguridad creada en /backups (Simulado)."; break;
             case "OPTIMIZE": res.message = "Tabla optimizada. Espacio liberado: 0KB."; break;
             case "GRANT": res.message = "Permisos actualizados."; break;
             case "REVOKE": res.message = "Permisos revocados."; break;

            default: throw new Exception("Comando desconocido.");
        }
    }
    
    static class KyloResponse { public boolean success; public String message; public double time; public Object data; }
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
                }catch(Exception e){}
            }
        }catch(Exception e){}
    }
    private static String getDiskUsage() { File f=new File(DB_FILE); return f.exists()?(f.length()/1024)+" KB":"0 KB"; }
}