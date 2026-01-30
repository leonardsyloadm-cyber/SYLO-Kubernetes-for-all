package com.sylo.kylo.core.transaction;

import com.sylo.kylo.core.structure.Tuple;
import java.util.*;
import java.util.concurrent.ConcurrentHashMap;

/**
 * Holds the private "Shadow Workspace" for a single transaction.
 * Changes here are invisible to others until COMMIT.
 */
public class TransactionContext {
    // private final String sessionId; // Unused

    public TransactionContext(String sessionId) {
        // this.sessionId = sessionId;
    }

    // Table -> List of new Tuples (Shadow Inserts)
    private final Map<String, List<Tuple>> shadowInserts = new ConcurrentHashMap<>();

    // Table -> Set of Deleted RIDs (Shadow Deletes)
    // These RIDs are effectively "invisible" to this transaction,
    // but still exist in the main storage until Commit.
    private final Map<String, Set<Long>> shadowDeletes = new ConcurrentHashMap<>();

    public void addInsert(String tableName, Tuple tuple) {
        shadowInserts.computeIfAbsent(tableName, k -> new ArrayList<>()).add(tuple);
    }

    public void addDelete(String tableName, long rid) {
        shadowDeletes.computeIfAbsent(tableName, k -> new HashSet<>()).add(rid);
    }

    public boolean isDeleted(String tableName, long rid) {
        Set<Long> deleted = shadowDeletes.get(tableName);
        return deleted != null && deleted.contains(rid);
    }

    public List<Tuple> getInserts(String tableName) {
        return shadowInserts.getOrDefault(tableName, Collections.emptyList());
    }

    public Map<String, List<Tuple>> getAllInserts() {
        return shadowInserts;
    }

    public Map<String, Set<Long>> getAllDeletes() {
        return shadowDeletes;
    }

    public void clear() {
        shadowInserts.clear();
        shadowDeletes.clear();
    }
}
