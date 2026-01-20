package com.sylo.kylo.core.catalog;

import java.util.HashMap;
import java.util.Map;

public class Catalog {
    private static Catalog instance;
    private final Map<String, Schema> tables; // Simplified. Map Name -> Schema

    private Catalog() {
        this.tables = new HashMap<>();
        // Load from file logic would go here.
    }

    public static synchronized Catalog getInstance() {
        if (instance == null) {
            instance = new Catalog();
        }
        return instance;
    }

    public void createTable(String tableName, Schema schema) {
        tables.put(tableName, schema);
        // Persist logic
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
}
