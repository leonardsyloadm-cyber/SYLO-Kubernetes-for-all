package com.sylo.kylo.core.sql;

import com.sylo.kylo.core.execution.ExecutionEngine;
import com.sylo.kylo.core.catalog.Catalog;
import com.sylo.kylo.core.catalog.Schema;
import com.sylo.kylo.core.catalog.Column;
import com.sylo.kylo.core.structure.*;

import java.util.*;
import java.util.regex.*;

public class KyloProcessor {

    private static final Pattern CREATE_PATTERN = Pattern.compile("\\((.*?)\\)");
    private static final Pattern INSERT_PATTERN = Pattern
            .compile("INSERT\\s+INTO\\s+(\\w+)\\s*(?:\\((.*?)\\))?\\s*VALUES\\s*\\((.*?)\\)", Pattern.CASE_INSENSITIVE);

    public static class KyloResponse {
        public boolean success;
        public String message;
        public Object data;
    }

    public static KyloResponse process(String query, ExecutionEngine engine) {
        KyloResponse res = new KyloResponse();
        try {
            // Support multi-statement scripts (e.g. USE DB1; CREATE TABLE...)
            String[] statements = query.split(";");
            String currentDB = "Default"; // Default context

            for (String stmt : statements) {
                if (stmt.trim().isEmpty())
                    continue;

                // Pass currentDB reference effectively
                currentDB = executeKyloQL(stmt.trim(), engine, res, currentDB);
            }
            res.success = true;
        } catch (Exception e) {
            e.printStackTrace();
            res.success = false;
            res.message = e.getMessage();
        }
        return res;
    }

    private static String executeKyloQL(String query, ExecutionEngine engine, KyloResponse res, String currentDB)
            throws Exception {
        String q = query.trim();
        String[] parts = q.split("\\s+");
        if (parts.length == 0)
            return currentDB;

        String verb = parts[0].toUpperCase();

        if (verb.equals("USE")) {
            if (parts.length > 1) {
                currentDB = parts[1];
                res.message = "Switched to " + currentDB;
            }
            return currentDB;
        }

        switch (verb) {
            case "CREATE":
                if (parts.length > 1 && parts[1].equalsIgnoreCase("DATABASE")) {
                    String db = parts[2];
                    Catalog.getInstance().createDatabase(db);
                    res.message = "DB Creada.";
                } else if (parts.length > 1 && parts[1].equalsIgnoreCase("TABLE")) {
                    String tbl = parts[2].split("\\(")[0];
                    Matcher m = CREATE_PATTERN.matcher(q);
                    if (!m.find())
                        throw new Exception("Error: Esquema inválido.");

                    List<Column> columns = new ArrayList<>();
                    for (String col : m.group(1).split(",")) {
                        String[] tokens = col.trim().split("\\s+");
                        String colName = tokens[0];
                        String typeStr = tokens[1].toUpperCase();
                        KyloType type = parseType(typeStr);
                        columns.add(new Column(colName, type, false));
                    }

                    // Use currentDB if no prefix
                    String k = tbl.contains(":") ? tbl : (currentDB + ":" + tbl);
                    Schema schema = new Schema(columns);
                    Catalog.getInstance().createTable(k, schema);
                    res.message = "Tabla Creada (Storage Engine) en " + currentDB;

                } else if (parts.length > 1 && parts[1].equalsIgnoreCase("INDEX")) {
                    // ... (Index logic)
                    Pattern pIdx = Pattern.compile("ON\\s+(\\w+)\\s*\\((\\w+)\\)", Pattern.CASE_INSENSITIVE);
                    Matcher mIdx = pIdx.matcher(q);
                    if (mIdx.find()) {
                        String t = mIdx.group(1);
                        String c = mIdx.group(2);
                        String fullT = t.contains(":") ? t : (currentDB + ":" + t);
                        engine.createIndex(fullT, c);
                        res.message = "Índice B+Tree creado en " + fullT + "." + c;
                    } else {
                        throw new Exception("Sintaxis CREATE INDEX inválida.");
                    }
                }
                break;

            case "INSERT":

                Matcher mIns = INSERT_PATTERN.matcher(q);
                if (!mIns.find())
                    throw new Exception("Sintaxis INSERT inválida.");
                String tName = mIns.group(1);
                String valsDef = mIns.group(3);
                String fullTableName = tName.contains(":") ? tName : (currentDB + ":" + tName);

                Schema schema = Catalog.getInstance()
                        .getTableSchema(fullTableName);
                if (schema == null)
                    throw new Exception("Tabla no existe: " + fullTableName);

                List<String> rawValues = new ArrayList<>();
                Matcher vm = Pattern.compile("'([^']*)'|([^,]+)")
                        .matcher(valsDef);
                while (vm.find())
                    rawValues.add(vm.group(1) != null ? vm.group(1) : vm.group(2).trim());

                if (rawValues.size() != schema.getColumnCount())
                    throw new Exception("Column count mismatch");
                Object[] tupleData = new Object[schema.getColumnCount()];
                for (int i = 0; i < schema.getColumnCount(); i++) {
                    tupleData[i] = parseValue(schema.getColumn(i).getType(), rawValues.get(i));
                }
                engine.insertTuple(fullTableName, tupleData);
                res.message = "Insertado en Disk Page.";
                break;

            case "SELECT":
                String st = "";
                // Simple parsing for SELECT * FROM table
                // or SELECT col, col FROM table
                int fromIdx = -1;
                for (int i = 0; i < parts.length; i++)
                    if (parts[i].equalsIgnoreCase("FROM"))
                        fromIdx = i;
                if (fromIdx == -1)
                    throw new Exception("Sintaxis SELECT inválida (Falta FROM)");

                st = parts[fromIdx + 1];
                String fullT = st.contains(":") ? st : (currentDB + ":" + st);

                // For now, full scan
                // java.util.function.Predicate<Tuple> pred = null; // No filtering parsed yet

                List<Object[]> rows = engine.scanTable(fullT); // Uses PlanNode internally now
                Schema s = Catalog.getInstance()
                        .getTableSchema(fullT);
                if (s == null)
                    throw new Exception("Tabla no encontrada: " + fullT);

                List<Map<String, Object>> l = new ArrayList<>();
                for (Object[] r : rows) {
                    Map<String, Object> m = new LinkedHashMap<>(); // Insert order
                    for (int i = 0; i < s.getColumnCount(); i++) {
                        m.put(s.getColumn(i).getName(), r[i]);
                    }
                    l.add(m);
                }
                res.data = l;
                res.message = "Filas leídas: " + l.size();
                break;

            default:
                res.message = "Comando no implementado en KyloProcessor: " + verb;
        }
        return currentDB;
    }

    private static KyloType parseType(String typeStr) {
        if (typeStr.contains("INT"))
            return new KyloInt();
        if (typeStr.contains("BIGINT"))
            return new KyloBigInt();
        if (typeStr.contains("TEXT") || typeStr.contains("VARCHAR"))
            return new KyloVarchar(255);
        if (typeStr.contains("BOOLEAN"))
            return new KyloBoolean();
        if (typeStr.contains("UUID"))
            return new KyloUuid();
        return new KyloVarchar(100);
    }

    private static Object parseValue(KyloType type, String raw) {
        if (raw.equalsIgnoreCase("NULL"))
            return null;
        try {
            if (type instanceof KyloInt)
                return Integer.parseInt(raw);
            if (type instanceof KyloBigInt)
                return Long.parseLong(raw);
            if (type instanceof KyloVarchar)
                return raw;
            if (type instanceof KyloBoolean) {
                if (raw.equals("1") || raw.equalsIgnoreCase("true"))
                    return true;
                return false;
            }
            if (type instanceof KyloUuid)
                return UUID.fromString(raw);
        } catch (Exception e) {
        }
        return raw;
    }
}
