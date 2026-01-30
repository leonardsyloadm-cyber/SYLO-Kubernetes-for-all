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

        // Initialize IndexManager to load metadata
        com.sylo.kylo.core.index.IndexManager.getInstance();
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

    public com.sylo.kylo.core.index.IndexManager getIndexManager() {
        return com.sylo.kylo.core.index.IndexManager.getInstance();
    }

    public com.sylo.kylo.core.constraint.ConstraintManager getConstraintManager() {
        return com.sylo.kylo.core.constraint.ConstraintManager.getInstance();
    }

    public void addConstraint(com.sylo.kylo.core.constraint.Constraint c) {
        getConstraintManager().addConstraint(c);
    }

    public void reset() {
        tables.clear();
        databases.clear();
        databases.add("Default");
        com.sylo.kylo.core.index.IndexManager.getInstance().reset(); // Need to verify if this exists
    }
}
