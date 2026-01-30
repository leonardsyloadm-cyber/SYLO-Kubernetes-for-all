package com.sylo.kylo.core.execution;

import com.sylo.kylo.core.catalog.Catalog;
import com.sylo.kylo.core.catalog.Schema;
import com.sylo.kylo.core.storage.BufferPoolManager;
import com.sylo.kylo.core.storage.DiskManager;
import com.sylo.kylo.core.storage.HeapFile;
import com.sylo.kylo.core.structure.RowHeader;
import com.sylo.kylo.core.structure.Tuple;
import com.sylo.kylo.core.structure.PlanNode;
import com.sylo.kylo.core.structure.ScanNode;
import com.sylo.kylo.core.structure.FilterNode;

import com.sylo.kylo.core.storage.StorageConstants;

import java.util.HashMap;
import java.util.Map;
import java.util.List;
import java.util.ArrayList;
import java.util.function.Predicate;

public class ExecutionEngine {
    private final DiskManager diskManager;
    private final BufferPoolManager bufferPool;
    private final Map<String, HeapFile> tableFiles;
    // Removed local tableIndices map, using IndexManager via Catalog

    public ExecutionEngine(String dbPath) {
        this.diskManager = new DiskManager(dbPath);
        this.bufferPool = new BufferPoolManager(diskManager, 500); // 500 pages cache
        this.tableFiles = new HashMap<>();
    }

    public BufferPoolManager getBufferPool() {
        return bufferPool;
    }

    // Overload for no session (AutoCommit / System)
    public void insertTuple(String tableName, Object[] values) {
        insertTuple(null, tableName, values);
    }

    public void insertTuple(String sessionId, String tableName, Object[] values) {
        // 0. Transaction Check
        if (sessionId != null) {
            com.sylo.kylo.core.transaction.TransactionManager tm = com.sylo.kylo.core.transaction.TransactionManager
                    .getInstance();
            if (tm.isInTransaction(sessionId)) {
                // Shadow Insert
                System.out.println("👻 Shadow Insert for Session " + sessionId);
                Schema schema = Catalog.getInstance().getTableSchema(tableName);
                // Validate first
                for (int i = 0; i < values.length; i++) {
                    if (values[i] != null)
                        schema.getColumn(i).getType().validate(values[i]);
                }
                RowHeader header = new RowHeader();
                Tuple t = new Tuple(header, values);
                tm.getTransaction(sessionId).addInsert(tableName, t);
                return;
            }
        }
        // Fallback to direct synchronous write
        insertTupleDirect(tableName, values);
    }

    public void insertTupleDirect(String tableName, Object[] values) {
        Catalog catalog = Catalog.getInstance();
        Schema schema = catalog.getTableSchema(tableName);
        // ... existing logic ...
        if (schema == null) {
            throw new IllegalArgumentException("Table " + tableName + " does not exist.");
        }

        // 1. Validate Types
        if (values.length != schema.getColumnCount()) {
            throw new IllegalArgumentException("Value count mismatch.");
        }
        for (int i = 0; i < values.length; i++) {
            Object val = values[i];
            if (val != null) {
                schema.getColumn(i).getType().validate(val);
            } else if (!schema.getColumn(i).isNullable()) {
                throw new IllegalArgumentException("Column " + schema.getColumn(i).getName() + " cannot be null.");
            }
        }

        // 2. Build Tuple
        RowHeader header = new RowHeader();
        Tuple tuple = new Tuple(header, values);

        // 3. Get/Create HeapFile for table
        HeapFile heapFile = tableFiles.computeIfAbsent(tableName, k -> new HeapFile(bufferPool));

        // FK Constraint Check
        com.sylo.kylo.core.index.IndexManager indexMgr = catalog.getIndexManager();

        // 3. Validate Constraints (FK, Check, etc.) BEFORE modifying anything
        try {
            com.sylo.kylo.core.constraint.ConstraintManager.getInstance().validateInsert(tableName, tuple, bufferPool);
        } catch (RuntimeException e) {
            throw e; // Fail fast
        }

        // 4. Insert into Heap (Prepare Phase)
        long rid = heapFile.insertTuple(tuple, schema);

        // 5. Insert into Indices (Commit Phase /w Rollback)
        try {
            for (int i = 0; i < schema.getColumnCount(); i++) {
                String colName = schema.getColumn(i).getName();
                if (indexMgr.hasIndex(tableName, colName)) {
                    com.sylo.kylo.core.index.BPlusTreeIndex idx = indexMgr.getIndex(tableName, colName, bufferPool);
                    if (idx != null) {
                        try {
                            idx.insert(values[i], rid);
                        } catch (Exception ex) {
                            System.err.println("CRITICAL ERROR inserting into index " + tableName + "." + colName +
                                    " Value: " + values[i] + ". RootID: " + idx.getRootPageId());
                            ex.printStackTrace();
                            throw ex;
                        }
                    }
                }
            }
        } catch (Exception e) {
            // ROLLBACK!
            // Mark the tuple in HeapFile as deleted immediately
            System.err.println("Index insertion failed: " + e.getMessage() + ". Rolling back Heap Insert.");
            deleteTupleByRid(tableName, rid);
            throw new RuntimeException("Transaction Aborted: " + e.getMessage() + " (State: " + tableName + ")", e);
        }
    }

    // Helper for Rollback
    public void deleteTupleByRid(String tableName, long rid) {
        int pageId = (int) (rid >> 32);
        int slotId = (int) (rid & 0xFFFFFFFFL);
        try {
            com.sylo.kylo.core.storage.Page page = bufferPool.fetchPage(new com.sylo.kylo.core.storage.PageId(pageId));
            page.markTupleDeleted(slotId);
        } catch (Exception e) {
            System.err.println("Fatal: Rollback failed for RID " + rid);
        }
    }

    // Legacy support for simple scan - executes plan immediately
    public List<Object[]> scanTable(String tableName) {
        return scanTable(null, tableName);
    }

    public List<Object[]> scanTable(String sessionId, String tableName) {
        PlanNode plan = createScanPlan(tableName, null);
        List<Object[]> results = new ArrayList<>();

        com.sylo.kylo.core.transaction.TransactionContext ctx = null;
        if (sessionId != null) {
            ctx = com.sylo.kylo.core.transaction.TransactionManager.getInstance().getTransaction(sessionId);
        }

        plan.open();
        try {
            while (true) {
                Tuple t = plan.next();
                if (t == null)
                    break;

                // Shadow Filter (Deletes)
                if (ctx != null && ctx.isDeleted(tableName, t.getRid())) {
                    continue; // Skip shadow-deleted row
                }

                results.add(t.getValues());
            }
        } finally {
            plan.close();
        }

        // Shadow Merge (Inserts)
        if (ctx != null) {
            List<Tuple> shadowInserts = ctx.getInserts(tableName);
            for (Tuple t : shadowInserts) {
                results.add(t.getValues());
            }
        }

        return results;
    }

    public PlanNode createScanPlan(String tableName, Predicate<Tuple> predicate) {
        // System Table Check
        if (tableName.toUpperCase().startsWith("INFORMATION_SCHEMA.") || tableName.equalsIgnoreCase("TABLES")
                || tableName.equalsIgnoreCase("COLUMNS")) {
            String subTable = tableName;
            if (tableName.toUpperCase().startsWith("INFORMATION_SCHEMA.")) {
                subTable = tableName.substring("INFORMATION_SCHEMA.".length());
            }
            return com.sylo.kylo.core.sys.SystemTableProvider.getSystemTableScan(subTable);
        }

        Catalog catalog = Catalog.getInstance();
        Schema schema = catalog.getTableSchema(tableName);
        if (schema == null)
            return null; // Or throw

        // Heap Scan
        // For ScanNode, we need a startPageId.
        // In simplified HeapFile, we assume Page 0 is start.
        ScanNode scan = new ScanNode(bufferPool, schema, 0);

        if (predicate != null) {
            return new FilterNode(scan, predicate);
        }
        return scan;
    }

    public void createIndex(String tableName, String columnName, String indexName) {
        Catalog catalog = Catalog.getInstance();
        if (catalog.getTableSchema(tableName) == null)
            throw new IllegalArgumentException("Table not found");

        com.sylo.kylo.core.index.IndexManager indexMgr = catalog.getIndexManager();
        if (indexMgr.hasIndex(tableName, columnName)) {
            throw new IllegalArgumentException("Index already exists");
        }

        // Create Root
        com.sylo.kylo.core.storage.Page root = bufferPool.newPage();
        int rootId = root.getPageId().getPageNumber();
        // Init as Leaf
        com.sylo.kylo.core.index.IndexPage idxPage = new com.sylo.kylo.core.index.IndexPage(root,
                com.sylo.kylo.core.index.IndexPage.IndexType.LEAF);
        idxPage.init(com.sylo.kylo.core.index.IndexPage.IndexType.LEAF,
                com.sylo.kylo.core.storage.StorageConstants.INVALID_PAGE_ID);

        // Register
        indexMgr.registerIndex(tableName, columnName, rootId, indexName);
        com.sylo.kylo.core.index.BPlusTreeIndex idx = indexMgr.getIndex(tableName, columnName, bufferPool);

        System.out.println("Building Index " + indexName + " for " + tableName + "." + columnName + "...");

        // Populate index from existing data (Full Table Scan)
        PlanNode scan = createScanPlan(tableName, null);
        scan.open();
        Schema schema = catalog.getTableSchema(tableName);
        int colIdx = -1;
        for (int i = 0; i < schema.getColumnCount(); i++) {
            if (schema.getColumn(i).getName().equals(columnName)) {
                colIdx = i;
                break;
            }
        }
        if (colIdx == -1)
            throw new IllegalArgumentException("Column not found");

        while (true) {
            Tuple t = scan.next();
            if (t == null)
                break;
            idx.insert(t.getValue(colIdx), t.getRid());
        }
        scan.close();
        System.out.println("Index build complete.");
    }

    // Overload for backward compatibility
    public void createIndex(String tableName, String columnName) {
        createIndex(tableName, columnName, "IDX_" + System.currentTimeMillis());
    }

    // Overload
    public int deleteTuple(String tableName, Predicate<Tuple> predicate) {
        return deleteTuple(null, tableName, predicate);
    }

    public int deleteTuple(String sessionId, String tableName, Predicate<Tuple> predicate) {
        if (sessionId != null) {
            com.sylo.kylo.core.transaction.TransactionManager tm = com.sylo.kylo.core.transaction.TransactionManager
                    .getInstance();
            if (tm.isInTransaction(sessionId)) {
                System.out.println("👻 Shadow Delete for Session " + sessionId);
                int count = 0;
                com.sylo.kylo.core.transaction.TransactionContext ctx = tm.getTransaction(sessionId);

                // 1. Mark Disk Tuples as Deleted (Shadow)
                // We restart scan to get RIDs
                PlanNode plan = createScanPlan(tableName, null);
                plan.open();
                while (true) {
                    Tuple t = plan.next();
                    if (t == null)
                        break;

                    // If already shadow-deleted, skip
                    if (ctx.isDeleted(tableName, t.getRid()))
                        continue;

                    if (predicate.test(t)) {
                        ctx.addDelete(tableName, t.getRid());
                        count++;
                    }
                }
                plan.close();

                // 2. Remove from Shadow Inserts
                List<Tuple> inserts = ctx.getInserts(tableName);
                if (inserts != null) {
                    java.util.Iterator<Tuple> it = inserts.iterator();
                    while (it.hasNext()) {
                        Tuple t = it.next();
                        if (predicate.test(t)) {
                            it.remove();
                            count++;
                        }
                    }
                }
                return count;
            }
        }
        return deleteTupleDirect(tableName, predicate);
    }

    public int deleteTupleDirect(String tableName, Predicate<Tuple> predicate) {
        PlanNode plan = createScanPlan(tableName, predicate);
        plan.open();
        int count = 0;

        Catalog catalog = Catalog.getInstance();
        Schema schema = catalog.getTableSchema(tableName);
        if (schema == null)
            return 0;

        com.sylo.kylo.core.index.IndexManager indexMgr = catalog.getIndexManager();

        int currentPageId = 0;
        // Basic scan for deletion (Should use Plan logic but here we access pages for
        // direct modification)
        // Actually the deleteTuple logic iterates pages directly which effectively
        // replicates ScanNode logic
        // We should reuse plan but we need Modify access.
        // For simplicity, keeping existing iteration logic but adding Index Deletion.

        while (true) {
            try {
                if (currentPageId == StorageConstants.INVALID_PAGE_ID)
                    break;
                com.sylo.kylo.core.storage.Page page = bufferPool
                        .fetchPage(new com.sylo.kylo.core.storage.PageId(currentPageId));
                int cnt = page.getSlotCount();
                boolean dirty = false;
                for (int i = 0; i < cnt; i++) {
                    Tuple t = page.getTuple(i, schema);
                    if (!t.getRowHeader().isDeleted() && predicate.test(t)) {
                        // FK Constraint Check (Delete)
                        try {
                            for (int c = 0; c < schema.getColumnCount(); c++) {
                                indexMgr.validateDelete(tableName, schema.getColumn(c).getName(), t.getValue(c),
                                        bufferPool);
                            }
                        } catch (RuntimeException e) {
                            throw e;
                        }

                        // DELETE
                        page.markTupleDeleted(i);
                        count++;
                        dirty = true;

                        // Remove from indices
                        for (int c = 0; c < schema.getColumnCount(); c++) {
                            String colName = schema.getColumn(c).getName();
                            if (indexMgr.hasIndex(tableName, colName)) {
                                com.sylo.kylo.core.index.BPlusTreeIndex idx = indexMgr.getIndex(tableName, colName,
                                        bufferPool);
                                if (idx != null) {
                                    idx.delete(t.getValue(c)); // Note: BPlusTreeIndex.delete is currently empty!
                                }
                            }
                        }
                    }
                }
                if (dirty)
                    page.setDirty(true); // Should be handled by markTupleDeleted theoretically if it touches buffer

                int next = page.getNextPageId();
                if (next == currentPageId || next == 0)
                    break;
                currentPageId = next;
            } catch (Exception e) {
                break;
            }
        }
        return count;
    }

    // Overload
    public void updateTuple(String tableName, java.util.Map<Integer, Object> changes, Predicate<Tuple> predicate) {
        updateTuple(null, tableName, changes, predicate);
    }

    public void updateTuple(String sessionId, String tableName, java.util.Map<Integer, Object> changes,
            Predicate<Tuple> predicate) {
        // Transactional Read-Modify-Write
        // 1. Scan for targets
        // Catalog.getInstance().getTableSchema(tableName); // Schema unused

        // We need RIDs. scanTable returning Object[] loses RIDs.
        // We need a scanTuple that returns Tuples!
        // Re-implementing scan loop here to get Tuples is safest.

        List<Tuple> toUpdate = new ArrayList<>();
        PlanNode plan = createScanPlan(tableName, null);
        com.sylo.kylo.core.transaction.TransactionContext ctx = null;
        if (sessionId != null) {
            ctx = com.sylo.kylo.core.transaction.TransactionManager.getInstance().getTransaction(sessionId);
        }

        plan.open();
        try {
            while (true) {
                Tuple t = plan.next();
                if (t == null)
                    break;
                if (ctx != null && ctx.isDeleted(tableName, t.getRid()))
                    continue;
                if (predicate.test(t)) {
                    toUpdate.add(t);
                }
            }
        } finally {
            plan.close();
        }

        // Check Shadow Inserts too
        if (ctx != null) {
            List<Tuple> shadows = ctx.getInserts(tableName);
            if (shadows != null) {
                for (Tuple t : shadows) {
                    if (predicate.test(t)) {
                        toUpdate.add(t);
                    }
                }
            }
        }

        // 2. Process Updates
        for (Tuple oldT : toUpdate) {
            // A. Delete Old
            // Construct specific predicate for RID or object identity
            final long targetRid = oldT.getRid();
            final Tuple targetObj = oldT;

            deleteTuple(sessionId, tableName, t -> {
                // Match by RID or Identity
                if (targetRid != 0)
                    return t.getRid() == targetRid;
                return t == targetObj; // Identity match for shadow tuples
            });

            // B. Create New
            Object[] newVals = oldT.getValues().clone();
            for (java.util.Map.Entry<Integer, Object> entry : changes.entrySet()) {
                newVals[entry.getKey()] = entry.getValue();
            }

            // C. Insert New
            insertTuple(sessionId, tableName, newVals);
        }
    }

    public void close() {
        bufferPool.flushAllPages();
        diskManager.close();
    }
}
