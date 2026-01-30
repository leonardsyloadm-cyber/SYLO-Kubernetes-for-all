package com.sylo.kylo.core.transaction;

import com.sylo.kylo.core.execution.ExecutionEngine;
import com.sylo.kylo.core.structure.Tuple;
import java.util.Map;
import java.util.Set;
import java.util.List;
import java.util.concurrent.ConcurrentHashMap;

/**
 * Manages active transactions and coordinates Commit/Rollback.
 */
public class TransactionManager {
    private static final TransactionManager INSTANCE = new TransactionManager();

    // SessionID -> Context
    private final Map<String, TransactionContext> activeTransactions = new ConcurrentHashMap<>();

    private TransactionManager() {
    }

    public static TransactionManager getInstance() {
        return INSTANCE;
    }

    public void beginTransaction(String sessionId) {
        // If already exists, maybe restart or ignore?
        // For simple AutoCommit=0 logic, we ensure a context exists.
        activeTransactions.putIfAbsent(sessionId, new TransactionContext(sessionId));
    }

    public TransactionContext getTransaction(String sessionId) {
        return activeTransactions.get(sessionId);
    }

    public boolean isInTransaction(String sessionId) {
        return activeTransactions.containsKey(sessionId);
    }

    public void commit(String sessionId, ExecutionEngine engine) {
        TransactionContext ctx = activeTransactions.get(sessionId);
        if (ctx == null)
            return; // Nothing to commit

        System.out.println("🔥 COMMITTING Transaction for Session: " + sessionId);

        synchronized (engine) { // Global Lock for Atomicity (Simplified)
            // 1. Apply Deletes
            for (Map.Entry<String, Set<Long>> entry : ctx.getAllDeletes().entrySet()) {
                String table = entry.getKey();
                for (Long rid : entry.getValue()) {
                    engine.deleteTupleByRid(table, rid); // Direct Delete in Engine
                }
            }

            // 2. Apply Inserts
            for (Map.Entry<String, List<Tuple>> entry : ctx.getAllInserts().entrySet()) {
                String table = entry.getKey();
                for (Tuple t : entry.getValue()) {
                    engine.insertTupleDirect(table, t.getValues()); // Direct Insert
                }
            }
        }

        // 3. Cleanup
        activeTransactions.remove(sessionId);
    }

    public void rollback(String sessionId) {
        System.out.println("↩️ ROLLING BACK Transaction for Session: " + sessionId);
        // Just discard the shadow workspace
        activeTransactions.remove(sessionId);
    }
}
