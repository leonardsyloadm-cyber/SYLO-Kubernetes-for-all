package com.sylo.kylo.core.catalog;

import java.util.HashMap;
import java.util.Map;

public class Catalog {
    private static Catalog instance;
    private final Map<String, Schema> tables; // Simplified. Map Name -> Schema

    private final java.util.Set<String> databases;

    private Catalog() {
        this.tables = new HashMap<>();
        this.databases = new java.util.HashSet<>();
        this.databases.add("Default"); // Default DB
    }

    public static synchronized Catalog getInstance() {
        if (instance == null) {
            instance = new Catalog();
        }
        return instance;
    }

    public void createDatabase(String dbName) {
        databases.add(dbName);
    }

    public java.util.Set<String> getDatabases() {
        return new java.util.HashSet<>(databases);
    }

    public void createTable(String tableName, Schema schema) {
        tables.put(tableName, schema);
    }

    public Schema getTableSchema(String tableName) {
        return tables.get(tableName);
    }

    public Map<String, Schema> getTables() {
        return new HashMap<>(tables);
    }

    public void removeTable(String tableName) {
        tables.remove(tableName);
    }

    public java.util.Set<String> getAllTableNames() {
        return tables.keySet();
    }
}
