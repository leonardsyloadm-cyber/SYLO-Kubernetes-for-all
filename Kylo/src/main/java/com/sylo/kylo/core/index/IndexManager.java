package com.sylo.kylo.core.index;

import com.sylo.kylo.core.storage.BufferPoolManager;
import java.io.*;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

public class IndexManager {
    private static IndexManager instance;
    private final Map<String, Integer> indexRootPages; // "Table.Column" -> RootPageID
    private final Map<String, BPlusTreeIndex> activeIndices;
    private final Map<String, String> indexNames; // "Table.Column" -> "IndexName"
    private final java.util.List<com.sylo.kylo.core.catalog.ForeignKey> foreignKeys;
    private final String metaFilePath = "kylo_system/indexes/index_roots.dat";
    private final String namesFilePath = "kylo_system/indexes/index_names.dat";
    private final String fkFilePath = "kylo_system/indexes/foreign_keys.dat";

    private IndexManager() {
        this.indexRootPages = new ConcurrentHashMap<>();
        this.activeIndices = new ConcurrentHashMap<>();
        this.indexNames = new ConcurrentHashMap<>();
        this.foreignKeys = new java.util.ArrayList<>();
        loadIndexMetadata();
        loadIndexNames();
        loadFKMetadata();
    }

    public void reset() {
        indexRootPages.clear();
        activeIndices.clear();
        indexNames.clear();
        foreignKeys.clear();
    }

    // ... index methods ...

    // --- FK Support ---
    public void registerForeignKey(String name, String childT, String childC, String parentT, String parentC) {
        foreignKeys.add(new com.sylo.kylo.core.catalog.ForeignKey(name, childT, childC, parentT, parentC));
        saveFKMetadata();
    }

    public void validateInsert(String table, String col, Object val, BufferPoolManager bpm) {
        // Check if this (table, col) refers to a parent
        for (com.sylo.kylo.core.catalog.ForeignKey fk : foreignKeys) {
            if (fk.childTable.equals(table) && fk.childColumn.equals(col)) {
                // Must exists in Parent (fk.parentTable, fk.parentColumn)
                // Assumption: Parent column MUST be indexed.
                BPlusTreeIndex parentIdx = getIndex(fk.parentTable, fk.parentColumn, bpm);
                if (parentIdx == null) {
                    throw new RuntimeException("Foreign Key Constraint Error: Parent column " + fk.parentTable + "."
                            + fk.parentColumn + " is not indexed. Cannot verify compliance.");
                }
                long rid = parentIdx.search(val);
                if (rid == -1) {
                    throw new RuntimeException("Integrity Constraint Violation: Key '" + val
                            + "' does not exist in parent table " + fk.parentTable);
                }
            }
        }
    }

    public void validateDelete(String table, String col, Object val, BufferPoolManager bpm) {
        // Check if anyone refers to this (table, col)
        for (com.sylo.kylo.core.catalog.ForeignKey fk : foreignKeys) {
            if (fk.parentTable.equals(table) && fk.parentColumn.equals(col)) {
                // Must NOT exist in Child (fk.childTable, fk.childColumn)
                BPlusTreeIndex childIdx = getIndex(fk.childTable, fk.childColumn, bpm);
                if (childIdx == null) {
                    // If child not indexed, we'd need full scan. For simple RDBMS, we might require
                    // index on FK too?
                    // Or scan.
                    // Let's require Index on Child FK column for simplicity/performance in this
                    // phase,
                    // or skip check if no index (dangerous but compliant with "IndexManager"
                    // responsibility).
                    // "Verify no Children exist".
                    // If we proceed without index, we risk corruption.
                    // I will throw warning or error?
                    // Error: "Cannot verify FK integrity because child column is not indexed".
                    throw new RuntimeException("Foreign Key Check Error: Child column " + fk.childTable + "."
                            + fk.childColumn + " missing index.");
                }
                long rid = childIdx.search(val);
                if (rid != -1) {
                    throw new RuntimeException(
                            "Integrity Constraint Violation: Cannot delete/update parent row because child rows exist in "
                                    + fk.childTable);
                }
            }
        }
    }

    private void loadFKMetadata() {
        File file = new File(fkFilePath);
        if (!file.exists())
            return;
        try (DataInputStream dis = new DataInputStream(new FileInputStream(file))) {
            int count = dis.readInt();
            for (int i = 0; i < count; i++) {
                String name = dis.readUTF();
                String ct = dis.readUTF();
                String cc = dis.readUTF();
                String pt = dis.readUTF();
                String pc = dis.readUTF();
                foreignKeys.add(new com.sylo.kylo.core.catalog.ForeignKey(name, ct, cc, pt, pc));
            }
        } catch (IOException e) {
            e.printStackTrace();
        }
    }

    private void saveFKMetadata() {
        try (DataOutputStream dos = new DataOutputStream(new FileOutputStream(fkFilePath))) {
            dos.writeInt(foreignKeys.size());
            for (com.sylo.kylo.core.catalog.ForeignKey fk : foreignKeys) {
                dos.writeUTF(fk.constraintName);
                dos.writeUTF(fk.childTable);
                dos.writeUTF(fk.childColumn);
                dos.writeUTF(fk.parentTable);
                dos.writeUTF(fk.parentColumn);
            }
        } catch (IOException e) {
            e.printStackTrace();
        }
    }

    public static synchronized IndexManager getInstance() {
        if (instance == null) {
            instance = new IndexManager();
        }
        return instance;
    }

    public void registerIndex(String tableName, String columnName, int rootPageId, String indexName) {
        String key = tableName + "." + columnName;
        indexRootPages.put(key, rootPageId);
        if (indexName != null)
            indexNames.put(key, indexName);
        saveIndexMetadata();
        saveIndexNames();
    }

    // Overload for backward compatibility
    public void registerIndex(String tableName, String columnName, int rootPageId) {
        registerIndex(tableName, columnName, rootPageId, "IDX_" + System.currentTimeMillis());
    }

    public BPlusTreeIndex getIndex(String tableName, String columnName, BufferPoolManager bpm) {
        String key = tableName + "." + columnName;
        if (!indexRootPages.containsKey(key)) {
            return null;
        }
        return activeIndices.computeIfAbsent(key, k -> {
            int rootId = indexRootPages.get(k);
            return new BPlusTreeIndex(bpm, rootId);
        });
    }

    public boolean hasIndex(String tableName, String columnName) {
        return indexRootPages.containsKey(tableName + "." + columnName);
    }

    public String getIndexName(String key) {
        return indexNames.getOrDefault(key, "PRIMARY/AUTO");
    }

    public java.util.Set<String> getIndexNames() {
        return new java.util.HashSet<>(indexRootPages.keySet());
    }

    public void dropIndex(String tableName, String columnName) {
        String key = tableName + "." + columnName;
        System.out.println("DEBUG: Attempting to drop index Key='" + key + "'");

        if (!indexRootPages.containsKey(key)) {
            System.out.println("DEBUG: Key not found in indexRootPages. Available keys: " + indexRootPages.keySet());
        } else {
            System.out.println("DEBUG: Key found. Removing.");
        }

        indexRootPages.remove(key);
        activeIndices.remove(key);
        indexNames.remove(key);
        saveIndexMetadata();
        saveIndexNames();
    }

    private void loadIndexMetadata() {
        File file = new File(metaFilePath);
        if (!file.exists())
            return;

        try (DataInputStream dis = new DataInputStream(new FileInputStream(file))) {
            int count = dis.readInt();
            for (int i = 0; i < count; i++) {
                String key = dis.readUTF();
                int rootId = dis.readInt();
                indexRootPages.put(key, rootId);
            }
            System.out.println("Loaded " + count + " index definitions.");
        } catch (IOException e) {
            System.err.println("Failed to load index metadata: " + e.getMessage());
        }
    }

    private void saveIndexMetadata() {
        File dir = new File("kylo_system/indexes");
        if (!dir.exists())
            dir.mkdirs();

        try (DataOutputStream dos = new DataOutputStream(new FileOutputStream(metaFilePath))) {
            dos.writeInt(indexRootPages.size());
            for (Map.Entry<String, Integer> entry : indexRootPages.entrySet()) {
                dos.writeUTF(entry.getKey());
                dos.writeInt(entry.getValue());
            }
        } catch (IOException e) {
            System.err.println("Failed to save index metadata: " + e.getMessage());
        }
    }

    private void loadIndexNames() {
        File file = new File(namesFilePath);
        if (!file.exists())
            return;
        try (DataInputStream dis = new DataInputStream(new FileInputStream(file))) {
            int count = dis.readInt();
            for (int i = 0; i < count; i++) {
                String key = dis.readUTF();
                String name = dis.readUTF();
                indexNames.put(key, name);
            }
        } catch (IOException e) {
            e.printStackTrace();
        }
    }

    private void saveIndexNames() {
        try (DataOutputStream dos = new DataOutputStream(new FileOutputStream(namesFilePath))) {
            dos.writeInt(indexNames.size());
            for (Map.Entry<String, String> entry : indexNames.entrySet()) {
                dos.writeUTF(entry.getKey());
                dos.writeUTF(entry.getValue());
            }
        } catch (IOException e) {
            e.printStackTrace();
        }
    }
}
