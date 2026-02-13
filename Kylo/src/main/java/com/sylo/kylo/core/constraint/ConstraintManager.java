package com.sylo.kylo.core.constraint;

import com.sylo.kylo.core.catalog.Catalog;
import com.sylo.kylo.core.index.IndexManager;
import com.sylo.kylo.core.structure.Tuple;

import java.io.*;
import java.util.*;
import java.util.concurrent.ConcurrentHashMap;

public class ConstraintManager {
    private static ConstraintManager instance;
    private Map<String, List<Constraint>> tableConstraints; // Table -> Constraints
    private static final String STORAGE_PATH = "kylo_system/settings/constraints.dat";

    private ConstraintManager() {
        tableConstraints = new ConcurrentHashMap<>();
        load();
    }

    public static synchronized ConstraintManager getInstance() {
        if (instance == null) {
            instance = new ConstraintManager();
        }
        return instance;
    }

    public void addConstraint(Constraint c) {
        tableConstraints.computeIfAbsent(c.getTable(), k -> new ArrayList<>()).add(c);
        save();
        System.out.println("Constraint added: " + c);
    }

    public List<Constraint> getConstraints(String tableName) {
        return tableConstraints.getOrDefault(tableName, Collections.emptyList());
    }

    public Set<String> getAllKeys() {
        return tableConstraints.keySet();
    }

    public void clearConstraints(String tableName) {
        tableConstraints.remove(tableName);
        save();
    }

    public void removeConstraint(String tableName, String constraintName) {
        List<Constraint> consts = tableConstraints.get(tableName);
        if (consts != null) {
            consts.removeIf(c -> c.getName().equalsIgnoreCase(constraintName));
            if (consts.isEmpty()) {
                tableConstraints.remove(tableName);
            }
            save();
            System.out.println("Constraint " + constraintName + " removed from " + tableName);
        }
    }

    // Validate Insert: Check FKs
    public void validateInsert(String tableName, Tuple tuple, com.sylo.kylo.core.storage.BufferPoolManager bpm) {
        List<Constraint> constraints = getConstraints(tableName);
        if (constraints.isEmpty())
            return;

        for (Constraint c : constraints) {
            if (c.getType() == Constraint.Type.FOREIGN_KEY) {
                checkForeignKey(c, tuple, bpm);
            }
        }
    }

    // FK Check Logic used by Insert
    private void checkForeignKey(Constraint c, Tuple tuple, com.sylo.kylo.core.storage.BufferPoolManager bpm) {
        // Limitation: Currently supporting Single Column FKs for simplicity
        if (c.getColumns().size() != 1 || c.getRefColumns().size() != 1) {
            System.err.println("WARN: Multi-column FK validation not yet fully implemented.");
            return;
        }

        String colName = c.getColumns().get(0);
        String refTable = c.getRefTable();
        String refCol = c.getRefColumns().get(0);

        // Get Value from Tuple
        com.sylo.kylo.core.catalog.Schema schema = Catalog.getInstance().getTableSchema(c.getTable());
        int colIdx = -1;
        for (int i = 0; i < schema.getColumnCount(); i++) {
            if (schema.getColumn(i).getName().equals(colName)) {
                colIdx = i;
                break;
            }
        }

        if (colIdx == -1)
            throw new RuntimeException("FK Error: Column " + colName + " not found in " + c.getTable());

        Object key = tuple.getValue(colIdx);
        if (key == null)
            return; // Nulls usually allowed unless NOT NULL constraint exists

        // Search in Parent Index
        IndexManager idxMgr = Catalog.getInstance().getIndexManager();

        // Check if column is indexed OR is part of a PRIMARY KEY / UNIQUE constraint
        boolean hasValidIndex = idxMgr.hasIndex(refTable, refCol);

        if (!hasValidIndex) {
            // Also accept if column is part of PRIMARY KEY or UNIQUE constraint
            boolean isPKorUnique = false;

            // Try to find constraints for refTable - refTable might not have schema prefix
            // So we check both refTable directly and also try to resolve it with current
            // schema
            List<Constraint> refTableConstraints = tableConstraints.get(refTable);

            // If not found, try with schema prefix from current table's schema
            if (refTableConstraints == null && c.getTable().contains(":")) {
                String schemaPrefix = c.getTable().substring(0, c.getTable().indexOf(":"));
                String fullRefTable = schemaPrefix + ":" + refTable;
                refTableConstraints = tableConstraints.get(fullRefTable);
            }

            if (refTableConstraints != null) {
                for (Constraint constraint : refTableConstraints) {
                    if ((constraint.getType() == Constraint.Type.PRIMARY_KEY ||
                            constraint.getType() == Constraint.Type.UNIQUE)) {
                        if (constraint.getColumns().contains(refCol)) {
                            isPKorUnique = true;
                            break;
                        }
                    }
                }
            }

            if (!isPKorUnique) {
                throw new RuntimeException(
                        "Foreign Key Violation: Referenced column " + refTable + "." + refCol + " MUST be indexed.");
            }
        }

        // Get the index - need to use full table name with schema prefix
        String fullRefTable = refTable;
        if (!idxMgr.hasIndex(refTable, refCol) && c.getTable().contains(":")) {
            // Construct full table name with schema prefix
            String schemaPrefix = c.getTable().substring(0, c.getTable().indexOf(":"));
            fullRefTable = schemaPrefix + ":" + refTable;
        }

        com.sylo.kylo.core.index.BPlusTreeIndex idx = idxMgr.getIndex(fullRefTable, refCol, bpm);
        long rid = idx.search(key);

        if (rid == -1) {
            throw new RuntimeException(
                    "Foreign Key Constraint Violation: Value '" + key + "' does not exist in parent table " + refTable);
        }
    }

    @SuppressWarnings("unchecked")
    private void load() {
        File f = new File(STORAGE_PATH);
        if (!f.exists())
            return;
        try (ObjectInputStream ois = new ObjectInputStream(new FileInputStream(f))) {
            tableConstraints = (Map<String, List<Constraint>>) ois.readObject();
            System.out.println("Loaded constraints for " + tableConstraints.size() + " tables.");
        } catch (Exception e) {
            System.err.println("Error loading constraints: " + e.getMessage());
        }
    }

    private void save() {
        File f = new File(STORAGE_PATH);
        f.getParentFile().mkdirs();
        try (ObjectOutputStream oos = new ObjectOutputStream(new FileOutputStream(f))) {
            oos.writeObject(tableConstraints);
        } catch (IOException e) {
            e.printStackTrace();
        }
    }
}
