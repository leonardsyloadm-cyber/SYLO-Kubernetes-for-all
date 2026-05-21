package com.sylo.kylo.net.redis;

import com.sylo.kylo.core.execution.ExecutionEngine;
import com.sylo.kylo.core.sql.KyloProcessor;
import com.sylo.kylo.core.sql.KyloProcessor.KyloResponse;
import java.util.List;

public class RedisCommandHandler {
    private final ExecutionEngine engine;

    public RedisCommandHandler(ExecutionEngine engine) {
        this.engine = engine;
    }

    public static void initStorage(ExecutionEngine engine) {
        // Create the internal KV table if it doesn't exist
        String createTable = "CREATE TABLE kylo_system.redis_kv(r_key VARCHAR(255), r_val VARCHAR(4096));";
        KyloProcessor.process(createTable, engine);
        
        // Ensure index exists for O(1) / O(log n) performance
        String createIndex = "CREATE INDEX ON kylo_system.redis_kv(r_key);";
        KyloProcessor.process(createIndex, engine);
    }

    public String executeCommand(List<String> args) {
        String cmd = args.get(0).toUpperCase();

        try {
            switch (cmd) {
                case "PING":
                    return "+PONG\r\n";
                    
                case "SET":
                    if (args.size() < 3) return "-ERR wrong number of arguments for 'set' command\r\n";
                    String key = escapeSql(args.get(1));
                    String val = escapeSql(args.get(2));
                    
                    // UPSERT logic: delete old, insert new. 
                    // (Since we don't have robust ON DUPLICATE KEY yet, we do DEL then INS)
                    KyloProcessor.process("DELETE FROM kylo_system.redis_kv WHERE r_key = '" + key + "'", engine);
                    KyloResponse resSet = KyloProcessor.process("INSERT INTO kylo_system.redis_kv (r_key, r_val) VALUES ('" + key + "', '" + val + "')", engine);
                    
                    if (resSet.success) return "+OK\r\n";
                    else return "-ERR " + resSet.message + "\r\n";

                case "GET":
                    if (args.size() < 2) return "-ERR wrong number of arguments for 'get' command\r\n";
                    String getKey = escapeSql(args.get(1));
                    KyloResponse resGet = KyloProcessor.process("SELECT * FROM kylo_system.redis_kv WHERE r_key = '" + getKey + "'", engine);
                    
                    if (resGet.success && resGet.data != null) {
                        @SuppressWarnings("unchecked")
                        java.util.List<java.util.Map<String, Object>> dataList = (java.util.List<java.util.Map<String, Object>>) resGet.data;
                        if (!dataList.isEmpty()) {
                            // Extract r_val from the first map
                            java.util.Map<String, Object> row = dataList.get(0);
                        Object value = row.get("r_val");
                        if (value == null) return "$-1\r\n"; // Null bulk string
                        
                        String strVal = value.toString();
                        return "$" + strVal.length() + "\r\n" + strVal + "\r\n";
                        } else {
                            return "$-1\r\n"; // Null bulk string (key not found)
                        }
                    } else {
                        return "$-1\r\n"; // Error or null data
                    }

                case "APPEND":
                    if (args.size() < 3) return "-ERR wrong number of arguments for 'append' command\r\n";
                    String appKey = escapeSql(args.get(1));
                    String appVal = escapeSql(args.get(2));
                    KyloResponse resApp = KyloProcessor.process("SELECT * FROM kylo_system.redis_kv WHERE r_key = '" + appKey + "'", engine);
                    String finalStr = appVal;
                    if (resApp.success && resApp.data != null) {
                        @SuppressWarnings("unchecked")
                        java.util.List<java.util.Map<String, Object>> dl = (java.util.List<java.util.Map<String, Object>>) resApp.data;
                        if (!dl.isEmpty()) {
                            Object v = dl.get(0).get("r_val");
                            if (v != null) finalStr = v.toString() + appVal;
                        }
                    }
                    KyloProcessor.process("DELETE FROM kylo_system.redis_kv WHERE r_key = '" + appKey + "'", engine);
                    KyloProcessor.process("INSERT INTO kylo_system.redis_kv (r_key, r_val) VALUES ('" + appKey + "', '" + finalStr + "')", engine);
                    return ":" + finalStr.length() + "\r\n";

                case "STRLEN":
                    if (args.size() < 2) return "-ERR wrong number of arguments for 'strlen' command\r\n";
                    String strKey = escapeSql(args.get(1));
                    KyloResponse resStr = KyloProcessor.process("SELECT * FROM kylo_system.redis_kv WHERE r_key = '" + strKey + "'", engine);
                    if (resStr.success && resStr.data != null) {
                        @SuppressWarnings("unchecked")
                        java.util.List<java.util.Map<String, Object>> dl = (java.util.List<java.util.Map<String, Object>>) resStr.data;
                        if (!dl.isEmpty()) {
                            Object v = dl.get(0).get("r_val");
                            if (v != null) return ":" + v.toString().length() + "\r\n";
                        }
                    }
                    return ":0\r\n";

                case "RENAME":
                    if (args.size() < 3) return "-ERR wrong number of arguments for 'rename' command\r\n";
                    String oldKey = escapeSql(args.get(1));
                    String newKey = escapeSql(args.get(2));
                    KyloResponse resRen = KyloProcessor.process("SELECT * FROM kylo_system.redis_kv WHERE r_key = '" + oldKey + "'", engine);
                    if (resRen.success && resRen.data != null) {
                        @SuppressWarnings("unchecked")
                        java.util.List<java.util.Map<String, Object>> dl = (java.util.List<java.util.Map<String, Object>>) resRen.data;
                        if (!dl.isEmpty()) {
                            Object v = dl.get(0).get("r_val");
                            if (v != null) {
                                KyloProcessor.process("DELETE FROM kylo_system.redis_kv WHERE r_key = '" + oldKey + "'", engine);
                                KyloProcessor.process("DELETE FROM kylo_system.redis_kv WHERE r_key = '" + newKey + "'", engine); // Override new key if exists
                                KyloProcessor.process("INSERT INTO kylo_system.redis_kv (r_key, r_val) VALUES ('" + newKey + "', '" + v.toString() + "')", engine);
                                return "+OK\r\n";
                            }
                        }
                    }
                    return "-ERR no such key\r\n";


                case "MSET":
                    if (args.size() < 3 || args.size() % 2 == 0) return "-ERR wrong number of arguments for 'mset' command\r\n";
                    for (int i = 1; i < args.size(); i += 2) {
                        String k = escapeSql(args.get(i));
                        String v = escapeSql(args.get(i+1));
                        KyloProcessor.process("DELETE FROM kylo_system.redis_kv WHERE r_key = '" + k + "'", engine);
                        KyloProcessor.process("INSERT INTO kylo_system.redis_kv (r_key, r_val) VALUES ('" + k + "', '" + v + "')", engine);
                    }
                    return "+OK\r\n";

                case "MGET":
                    if (args.size() < 2) return "-ERR wrong number of arguments for 'mget' command\r\n";
                    StringBuilder mgetResp = new StringBuilder();
                    mgetResp.append("*").append(args.size() - 1).append("\r\n");
                    for (int i = 1; i < args.size(); i++) {
                        String k = escapeSql(args.get(i));
                        KyloResponse r = KyloProcessor.process("SELECT * FROM kylo_system.redis_kv WHERE r_key = '" + k + "'", engine);
                        if (r.success && r.data != null) {
                            @SuppressWarnings("unchecked")
                            java.util.List<java.util.Map<String, Object>> dl = (java.util.List<java.util.Map<String, Object>>) r.data;
                            if (!dl.isEmpty()) {
                                Object v = dl.get(0).get("r_val");
                                if (v == null) mgetResp.append("$-1\r\n");
                                else {
                                    String sv = v.toString();
                                    mgetResp.append("$").append(sv.length()).append("\r\n").append(sv).append("\r\n");
                                }
                            } else {
                                mgetResp.append("$-1\r\n");
                            }
                        } else {
                            mgetResp.append("$-1\r\n");
                        }
                    }
                    return mgetResp.toString();

                case "INCR":
                case "DECR":
                    if (args.size() != 2) return "-ERR wrong number of arguments\r\n";
                    String cKey = escapeSql(args.get(1));
                    int diff = cmd.equals("INCR") ? 1 : -1;
                    KyloResponse cRes = KyloProcessor.process("SELECT * FROM kylo_system.redis_kv WHERE r_key = '" + cKey + "'", engine);
                    long finalVal = diff;
                    if (cRes.success && cRes.data != null) {
                         @SuppressWarnings("unchecked")
                         java.util.List<java.util.Map<String, Object>> dl = (java.util.List<java.util.Map<String, Object>>) cRes.data;
                         if (!dl.isEmpty()) {
                             Object v = dl.get(0).get("r_val");
                             try {
                                 finalVal = Long.parseLong(v.toString()) + diff;
                             } catch (Exception e) {
                                 return "-ERR value is not an integer or out of range\r\n";
                             }
                         }
                    }
                    KyloProcessor.process("DELETE FROM kylo_system.redis_kv WHERE r_key = '" + cKey + "'", engine);
                    KyloProcessor.process("INSERT INTO kylo_system.redis_kv (r_key, r_val) VALUES ('" + cKey + "', '" + finalVal + "')", engine);
                    return ":" + finalVal + "\r\n";


                case "DEL":
                    if (args.size() < 2) return "-ERR wrong number of arguments for 'del' command\r\n";
                    int deleted = 0;
                    for (int i = 1; i < args.size(); i++) {
                        String delKey = escapeSql(args.get(i));
                        KyloResponse resDel = KyloProcessor.process("DELETE FROM kylo_system.redis_kv WHERE r_key = '" + delKey + "'", engine);
                        if (resDel.success) deleted++; // simplified tracking
                    }
                    return ":" + deleted + "\r\n";

                case "EXISTS":
                    if (args.size() < 2) return "-ERR wrong number of arguments for 'exists' command\r\n";
                    int exists = 0;
                    for (int i = 1; i < args.size(); i++) {
                        String exKey = escapeSql(args.get(i));
                        KyloResponse resEx = KyloProcessor.process("SELECT * FROM kylo_system.redis_kv WHERE r_key = '" + exKey + "'", engine);
                        if (resEx.success && resEx.data != null) {
                            @SuppressWarnings("unchecked")
                            java.util.List<java.util.Map<String, Object>> exData = (java.util.List<java.util.Map<String, Object>>) resEx.data;
                            if (!exData.isEmpty()) {
                                exists++;
                            }
                        }
                    }
                    return ":" + exists + "\r\n";

                case "KEYS":
                    // Simplified KEYS implementation (ignores complex patterns, just returns all or matches prefix roughly)
                    KyloResponse kRes = KyloProcessor.process("SELECT * FROM kylo_system.redis_kv", engine);
                    if (kRes.success && kRes.data != null) {
                         @SuppressWarnings("unchecked")
                         java.util.List<java.util.Map<String, Object>> dl = (java.util.List<java.util.Map<String, Object>>) kRes.data;
                         StringBuilder keysResp = new StringBuilder();
                         keysResp.append("*").append(dl.size()).append("\r\n");
                         for(java.util.Map<String, Object> row : dl) {
                             String foundKey = row.get("r_key").toString();
                             keysResp.append("$").append(foundKey.length()).append("\r\n").append(foundKey).append("\r\n");
                         }
                         return keysResp.toString();
                    }
                    return "*0\r\n";

                case "FLUSHDB":
                case "FLUSHALL":
                    KyloProcessor.process("DELETE FROM kylo_system.redis_kv", engine);
                    return "+OK\r\n";
                    
                case "DBSIZE":
                    KyloResponse dbRes = KyloProcessor.process("SELECT * FROM kylo_system.redis_kv", engine);
                    int count = 0;
                    if (dbRes.success && dbRes.data != null) {
                         @SuppressWarnings("unchecked")
                         java.util.List<java.util.Map<String, Object>> dl = (java.util.List<java.util.Map<String, Object>>) dbRes.data;
                         count = dl.size();
                    }
                    return ":" + count + "\r\n";

                case "COMMAND":
                    return "+OK\r\n"; // Dummy response for client initialization

                default:
                    return "-ERR unknown command '" + cmd + "'\r\n";
            }
        } catch (Exception e) {
            return "-ERR internal error: " + e.getMessage() + "\r\n";
        }
    }
    
    private String escapeSql(String val) {
        return val.replace("'", "''");
    }
}
