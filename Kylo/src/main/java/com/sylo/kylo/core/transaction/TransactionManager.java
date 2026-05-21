package com.sylo.kylo.core.transaction;

import com.sylo.kylo.core.execution.ExecutionEngine;
import com.sylo.kylo.core.structure.Tuple;
import java.util.Map;
import java.util.Set;
import java.util.List;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.locks.ReentrantLock;

/**
 * Manages active transactions and coordinates Commit/Rollback.
 */
public class TransactionManager {
    private static final TransactionManager INSTANCE = new TransactionManager();

    // SessionID -> Context
    private final Map<String, TransactionContext> activeTransactions = new ConcurrentHashMap<>();
    
    // Table Level Locks for Commit Atomicity
    private final Map<String, ReentrantLock> tableLocks = new ConcurrentHashMap<>();

    private TransactionManager() {
    }

    public static TransactionManager getInstance() {
        return INSTANCE;
    }

    public void beginTransaction(String sessionId) {
        activeTransactions.putIfAbsent(sessionId, new TransactionContext(sessionId));
    }

    public TransactionContext getTransaction(String sessionId) {
        return activeTransactions.get(sessionId);
    }

    public boolean isInTransaction(String sessionId) {
        return activeTransactions.containsKey(sessionId);
    }
    
    private ReentrantLock getTableLock(String tableName) {
        return tableLocks.computeIfAbsent(tableName, k -> new ReentrantLock());
    }

    public void commit(String sessionId, ExecutionEngine engine) {
        TransactionContext ctx = activeTransactions.get(sessionId);
        if (ctx == null)
            return; // Nothing to commit

        System.out.println("🔥 COMMITTING Transaction for Session: " + sessionId);

        StringBuilder walOps = new StringBuilder();
        for (Map.Entry<String, Set<Long>> entry : ctx.getAllDeletes().entrySet()) {
            for (Long rid : entry.getValue()) {
                walOps.append("DEL|").append(entry.getKey()).append("|").append(rid).append("\n");
            }
        }
        for (Map.Entry<String, List<Tuple>> entry : ctx.getAllInserts().entrySet()) {
            for (Tuple t : entry.getValue()) {
                walOps.append("INS|").append(entry.getKey()).append("|").append(java.util.Arrays.toString(t.getValues())).append("\n");
            }
        }
        WriteAheadLog.appendTx(sessionId, walOps.toString());

        // We sort the table names to prevent deadlocks when locking multiple tables
        java.util.TreeSet<String> tablesToLock = new java.util.TreeSet<>();
        tablesToLock.addAll(ctx.getAllDeletes().keySet());
        tablesToLock.addAll(ctx.getAllInserts().keySet());
        
        for (String table : tablesToLock) {
            getTableLock(table).lock();
        }

        boolean commitSuccess = true;
        try {
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
                    // Note: if insertTupleDirect fails, it internally rolls back its own heap insertion
                    // but we must still throw to trigger the global transaction rollback.
                }
            }
        } catch (Exception e) {
            commitSuccess = false;
            System.err.println("❌ FATAL COMMIT ERROR: " + e.getMessage() + ". Initiating Physical Rollback...");
            e.printStackTrace();
            
            // Undo Inserts that were added to WAL but now failed in physical engine.
            // Actually, if we fail halfway, we need to undo the Deletes that succeeded,
            // but we don't have the original tuple data here easily (just RIDs).
            // This is a partial UNDO. A full ARIES undo would read the before-image from WAL.
            // For now, we rely on WAL ARIES recovery for full undo, but we should at least
            // try to mark the transaction as aborted in WAL.
            WriteAheadLog.appendTx(sessionId, "TX ABORT|" + sessionId + "\n");
        } finally {
            for (String table : tablesToLock) {
                getTableLock(table).unlock();
            }
        }

        // 3. Cleanup
        if (commitSuccess) {
            activeTransactions.remove(sessionId);
        } else {
            // If commit failed, we still remove it from active memory, 
            // but we mark it as aborted so WAL knows.
            activeTransactions.remove(sessionId);
            throw new RuntimeException("Transaction aborted due to commit failure.");
        }
    }

    public void rollback(String sessionId) {
        System.out.println("↩️ ROLLING BACK Transaction for Session: " + sessionId);
        // Just discard the shadow workspace
        activeTransactions.remove(sessionId);
    }
}
