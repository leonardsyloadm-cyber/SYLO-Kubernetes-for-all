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

        // Load persisted catalog data (databases and user tables)
        load();
    }

    public static synchronized Catalog getInstance() {
        if (instance == null) {
            instance = new Catalog();
        }
        return instance;
    }

    public void createDatabase(String dbName) {
        databases.add(dbName);
        save();
    }

    public java.util.Set<String> getDatabases() {
        return new java.util.HashSet<>(databases);
    }

    public void dropDatabase(String dbName) {
        if (!databases.contains(dbName))
            return;
        databases.remove(dbName);

        // Remove all tables belonging to this DB
        java.util.List<String> tablesToRemove = new java.util.ArrayList<>();
        for (String t : tables.keySet()) {
            if (t.startsWith(dbName + ":")) {
                tablesToRemove.add(t);
            }
        }

        for (String t : tablesToRemove) {
            tables.remove(t);
            // Also remove constraints/indexes?
            // ConstraintManager stores by tableName, so we should clean up there too if
            // possible
            // But for now, removing from Catalog tables map is the primary step.
            System.out.println("Catalog: Dropped table " + t + " due to DROP DATABASE " + dbName);
        }
        save();
    }

    public void createTable(String tableName, Schema schema) {
        tables.put(tableName, schema);
        save();
    }

    public Schema getTableSchema(String tableName) {
        return tables.get(tableName);
    }

    public Map<String, Schema> getTables() {
        return new HashMap<>(tables);
    }

    public void removeTable(String tableName) {
        tables.remove(tableName);
        save();
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

    // Schema Modification Support
    public void alterTableAddColumn(String tableName, Column newCol) {
        Schema oldSchema = tables.get(tableName);
        if (oldSchema != null) {
            java.util.List<Column> newCols = new java.util.ArrayList<>(oldSchema.getColumns());
            newCols.add(newCol);
            tables.put(tableName, new Schema(newCols));
            System.out.println("Catalog: Added column " + newCol.getName() + " to " + tableName);
            save();
        }
    }

    public void alterTableModifyColumn(String tableName, Column newCol) {
        Schema oldSchema = tables.get(tableName);
        if (oldSchema != null) {
            java.util.List<Column> newCols = new java.util.ArrayList<>();
            for (Column c : oldSchema.getColumns()) {
                if (c.getName().equalsIgnoreCase(newCol.getName())) {
                    newCols.add(newCol); // Replace
                } else {
                    newCols.add(c);
                }
            }
            tables.put(tableName, new Schema(newCols));
            System.out.println("Catalog: Modified column " + newCol.getName() + " in " + tableName);
            save();
        }
    }

    // ALTER TABLE CHANGE COLUMN support (rename + type change)
    public void alterTableChangeColumn(String tableName, String oldColName, Column newCol) {
        Schema oldSchema = tables.get(tableName);
        if (oldSchema != null) {
            java.util.List<Column> newCols = new java.util.ArrayList<>();
            boolean found = false;
            for (Column c : oldSchema.getColumns()) {
                if (c.getName().equalsIgnoreCase(oldColName)) {
                    newCols.add(newCol); // Replace with new name/type
                    found = true;
                } else {
                    newCols.add(c);
                }
            }
            if (found) {
                tables.put(tableName, new Schema(newCols));
                System.out.println("Catalog: Changed column from '" + oldColName + "' to '" + newCol.getName() + "' in "
                        + tableName);
            } else {
                System.err.println("Catalog: Column '" + oldColName + "' not found in " + tableName);
            }
            save();
        }
    }

    public void alterTableDropColumn(String tableName, String colName) {
        Schema oldSchema = tables.get(tableName);
        if (oldSchema != null) {
            java.util.List<Column> newCols = new java.util.ArrayList<>();
            for (Column c : oldSchema.getColumns()) {
                if (!c.getName().equalsIgnoreCase(colName)) {
                    newCols.add(c);
                }
            }
            tables.put(tableName, new Schema(newCols));
            System.out.println("Catalog: Dropped column " + colName + " from " + tableName);
            save();
        }
    }

    public void addConstraint(com.sylo.kylo.core.constraint.Constraint c) {
        getConstraintManager().addConstraint(c);
    }

    public com.sylo.kylo.core.routine.RoutineManager getRoutineManager() {
        return com.sylo.kylo.core.routine.RoutineManager.getInstance();
    }

    public com.sylo.kylo.core.view.ViewManager getViewManager() {
        return com.sylo.kylo.core.view.ViewManager.getInstance();
    }

    public com.sylo.kylo.core.trigger.TriggerManager getTriggerManager() {
        return com.sylo.kylo.core.trigger.TriggerManager.getInstance();
    }

    public com.sylo.kylo.core.event.EventManager getEventManager() {
        return com.sylo.kylo.core.event.EventManager.getInstance();
    }

    public void reset() {
        tables.clear();
        databases.clear();
        databases.add("Default");
        com.sylo.kylo.core.index.IndexManager.getInstance().reset(); // Need to verify if this exists
        save();
    }

    // Persistence
    private static final String STORAGE_PATH = "kylo_system/settings/catalog.dat";

    @SuppressWarnings("unchecked")
    private void load() {
        java.io.File f = new java.io.File(STORAGE_PATH);
        if (!f.exists())
            return;
        try (java.io.ObjectInputStream ois = new java.io.ObjectInputStream(new java.io.FileInputStream(f))) {
            Map<String, Object> data = (Map<String, Object>) ois.readObject();
            if (data.containsKey("tables")) {
                this.tables.putAll((Map<String, Schema>) data.get("tables"));
            }
            if (data.containsKey("databases")) {
                this.databases.addAll((java.util.Set<String>) data.get("databases"));
            }
            System.out.println("Catalog: Loaded " + tables.size() + " tables and " + databases.size() + " databases.");
        } catch (Exception e) {
            System.err.println("Catalog: Error loading metadata: " + e.getMessage());
        }
    }

    private void save() {
        java.io.File f = new java.io.File(STORAGE_PATH);
        f.getParentFile().mkdirs();
        try (java.io.ObjectOutputStream oos = new java.io.ObjectOutputStream(new java.io.FileOutputStream(f))) {
            Map<String, Object> data = new HashMap<>();
            data.put("tables", tables);
            data.put("databases", databases);
            oos.writeObject(data);
        } catch (java.io.IOException e) {
            e.printStackTrace();
        }
    }

    public String generateDDL(String tableName) {
        Schema schema = tables.get(tableName);
        if (schema == null) {
            return null;
        }

        StringBuilder ddl = new StringBuilder();
        // Remove db prefix if present for clean DDL
        String shortName = tableName;
        if (shortName.contains(":")) {
            shortName = shortName.split(":")[1];
        }

        ddl.append("CREATE TABLE `").append(shortName).append("` (\n");

        // 1. Columns
        for (int i = 0; i < schema.getColumnCount(); i++) {
            Column col = schema.getColumn(i);
            ddl.append("  `").append(col.getName()).append("` ");

            // Type Mapping
            com.sylo.kylo.core.structure.KyloType type = col.getType();
            if (type instanceof com.sylo.kylo.core.structure.KyloInt) {
                ddl.append("INT");
            } else if (type instanceof com.sylo.kylo.core.structure.KyloBigInt) {
                ddl.append("BIGINT");
            } else if (type instanceof com.sylo.kylo.core.structure.KyloVarchar) {
                ddl.append("VARCHAR(").append(type.getFixedSize()).append(")");
            } else if (type instanceof com.sylo.kylo.core.structure.KyloText) {
                ddl.append("TEXT");
            } else if (type instanceof com.sylo.kylo.core.structure.KyloBoolean) {
                ddl.append("TINYINT(1)");
            } else {
                ddl.append("VARCHAR(255)"); // Fallback
            }

            if (!col.isNullable()) {
                ddl.append(" NOT NULL");
            } else {
                ddl.append(" DEFAULT NULL");
            }

            if (i < schema.getColumnCount() - 1) {
                ddl.append(",");
            }
            ddl.append("\n");
        }

        // 2. Constraints (PK, Unique, FK)
        java.util.List<com.sylo.kylo.core.constraint.Constraint> constraints = getConstraintManager()
                .getConstraints(tableName);

        if (!constraints.isEmpty()) {
            ddl.append(",\n");
            for (int i = 0; i < constraints.size(); i++) {
                com.sylo.kylo.core.constraint.Constraint c = constraints.get(i);
                ddl.append("  ");
                if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.PRIMARY_KEY) {
                    ddl.append("PRIMARY KEY (");
                } else if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.UNIQUE) {
                    ddl.append("UNIQUE KEY `").append(c.getName()).append("` (");
                } else if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.FOREIGN_KEY) {
                    ddl.append("CONSTRAINT `").append(c.getName()).append("` FOREIGN KEY (");
                }

                // Columns
                for (int k = 0; k < c.getColumns().size(); k++) {
                    ddl.append("`").append(c.getColumns().get(k)).append("`");
                    if (k < c.getColumns().size() - 1)
                        ddl.append(",");
                }
                ddl.append(")");

                // FK References
                if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.FOREIGN_KEY) {
                    ddl.append(" REFERENCES `").append(c.getRefTable().split(":")[1]).append("` ("); // simplify ref
                                                                                                     // table name
                    for (int k = 0; k < c.getRefColumns().size(); k++) {
                        ddl.append("`").append(c.getRefColumns().get(k)).append("`");
                        if (k < c.getRefColumns().size() - 1)
                            ddl.append(",");
                    }
                    ddl.append(")");
                }

                if (i < constraints.size() - 1) {
                    ddl.append(",\n");
                }
            }
        }

        ddl.append("\n) ENGINE=KyloDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci");
        return ddl.toString();
    }
}
