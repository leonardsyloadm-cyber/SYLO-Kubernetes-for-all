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

import java.io.File;
import java.util.HashMap;
import java.util.Map;
import java.util.List;
import java.util.ArrayList;
import java.util.function.Predicate;

public class ExecutionEngine {
    private final File dataDir;
    private final Map<String, TableStorage> tableStorage = new HashMap<>();

    public ExecutionEngine(String dataDirPath) {
        this.dataDir = new File(dataDirPath);
        if (!dataDir.exists()) {
            dataDir.mkdirs();
        }
    }

    private synchronized TableStorage getTableStorage(String tableName) {
        // Resolve canonical name (Catalog uses specific casing, files can be
        // lowercase?)
        // Catalog handles name resolution. We assume TableName passed here is correct
        // or we use it as is for filename.
        // To avoid case sensitivity issues on Linux, maybe force lowercase for
        // filename?
        // But Catalog preserves case. Let's use exact name for now.
        return tableStorage.computeIfAbsent(tableName, k -> {
            // Sanitize filename?
            String safeName = k.replaceAll(":", "_"); // Handle "DB:Table" format
            File dbFile = new File(dataDir, safeName + ".db");
            DiskManager dm = new DiskManager(dbFile.getAbsolutePath());
            BufferPoolManager bpm = new BufferPoolManager(dm, 500); // 500 pages per table
            HeapFile hf = new HeapFile(bpm);
            return new TableStorage(dm, bpm, hf);
        });
    }

    private static class TableStorage {
        final DiskManager diskManager;
        final BufferPoolManager bufferPool;
        final HeapFile heapFile;

        TableStorage(DiskManager dm, BufferPoolManager bpm, HeapFile hf) {
            this.diskManager = dm;
            this.bufferPool = bpm;
            this.heapFile = hf;
        }

        void close() {
            bufferPool.flushAllPages();
            diskManager.close();
        }
    }

    public long getTablePageCount(String tableName) {
        TableStorage ts = getTableStorage(tableName);
        return ts.bufferPool.getNumPages();
    }

    public com.sylo.kylo.core.storage.BufferPoolManager getBufferPool(String tableName) {
        return getTableStorage(tableName).bufferPool;
    }

    // IMPORTANT: Some methods need general BufferPool? No, only per table.
    // What about transactions? They are logical.

    public void insertTuple(String tableName, Object[] values) {
        insertTuple(null, tableName, values);
    }

    public void insertTuple(String sessionId, String tableName, Object[] values) {
        if (sessionId != null) {
            com.sylo.kylo.core.transaction.TransactionManager tm = com.sylo.kylo.core.transaction.TransactionManager
                    .getInstance();
            if (tm.isInTransaction(sessionId)) {
                System.out.println("👻 Shadow Insert for Session " + sessionId);
                Schema schema = Catalog.getInstance().getTableSchema(tableName);
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
        insertTupleDirect(tableName, values);
    }

    public void insertTupleDirect(String tableName, Object[] values) {
        Catalog catalog = Catalog.getInstance();
        Schema schema = catalog.getTableSchema(tableName);
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
        TableStorage ts = getTableStorage(tableName);

        // FK Constraint Check
        com.sylo.kylo.core.index.IndexManager indexMgr = catalog.getIndexManager();

        // 4. Insert into Heap (Prepare Phase)
        long rid = ts.heapFile.insertTuple(tuple, schema);

        // 5. Insert into Indices (Commit Phase /w Rollback)
        try {
            for (int i = 0; i < schema.getColumnCount(); i++) {
                String colName = schema.getColumn(i).getName();
                if (indexMgr.hasIndex(tableName, colName)) {
                    com.sylo.kylo.core.index.BPlusTreeIndex idx = indexMgr.getIndex(tableName, colName, ts.bufferPool);
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
            System.err.println("Index insertion failed: " + e.getMessage() + ". Rolling back Heap Insert.");
            deleteTupleByRid(tableName, rid);
            throw new RuntimeException("Transaction Aborted: " + e.getMessage() + " (State: " + tableName + ")", e);
        }
    }

    public void deleteTupleByRid(String tableName, long rid) {
        TableStorage ts = getTableStorage(tableName);
        int pageId = (int) (rid >> 32);
        int slotId = (int) (rid & 0xFFFFFFFFL);
        try {
            com.sylo.kylo.core.storage.Page page = ts.bufferPool
                    .fetchPage(new com.sylo.kylo.core.storage.PageId(pageId));
            page.markTupleDeleted(slotId);
        } catch (Exception e) {
            System.err.println("Fatal: Rollback failed for RID " + rid);
        }
    }

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

                if (ctx != null && ctx.isDeleted(tableName, t.getRid())) {
                    continue;
                }

                results.add(t.getValues());
            }
        } finally {
            plan.close();
        }

        if (ctx != null) {
            List<Tuple> shadowInserts = ctx.getInserts(tableName);
            for (Tuple t : shadowInserts) {
                results.add(t.getValues());
            }
        }

        return results;
    }

    public PlanNode createScanPlan(String tableName, Predicate<Tuple> predicate) {
        String upperTable = tableName.toUpperCase();
        if (upperTable.startsWith("INFORMATION_SCHEMA.") || upperTable.startsWith("INFORMATION_SCHEMA:")
                || upperTable.endsWith("TABLES") || upperTable.endsWith("COLUMNS")
                || upperTable.endsWith("KEY_COLUMN_USAGE") || upperTable.endsWith("REFERENTIAL_CONSTRAINTS")
                || upperTable.endsWith("SCHEMATA") || upperTable.endsWith("STATISTICS")
                || upperTable.endsWith("VIEWS") || upperTable.endsWith("TABLE_CONSTRAINTS")) {

            String subTable = tableName;
            if (upperTable.contains("INFORMATION_SCHEMA.") || upperTable.contains("INFORMATION_SCHEMA:")) {
                String[] parts = tableName.split("[:.]");
                if (parts.length > 1) {
                    subTable = parts[parts.length - 1];
                }
            }
            return com.sylo.kylo.core.sys.SystemTableProvider.getSystemTableScan(subTable);
        }

        Catalog catalog = Catalog.getInstance();
        Schema schema = catalog.getTableSchema(tableName);
        if (schema == null)
            return null;

        TableStorage ts = getTableStorage(tableName);
        ScanNode scan = new ScanNode(ts.bufferPool, schema, 0);

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

        TableStorage ts = getTableStorage(tableName);

        com.sylo.kylo.core.storage.Page root = ts.bufferPool.newPage();
        int rootId = root.getPageId().getPageNumber();
        com.sylo.kylo.core.index.IndexPage idxPage = new com.sylo.kylo.core.index.IndexPage(root,
                com.sylo.kylo.core.index.IndexPage.IndexType.LEAF);
        idxPage.init(com.sylo.kylo.core.index.IndexPage.IndexType.LEAF,
                com.sylo.kylo.core.storage.StorageConstants.INVALID_PAGE_ID);

        indexMgr.registerIndex(tableName, columnName, rootId, indexName);
        com.sylo.kylo.core.index.BPlusTreeIndex idx = indexMgr.getIndex(tableName, columnName, ts.bufferPool);

        System.out.println("Building Index " + indexName + " for " + tableName + "." + columnName + "...");

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

    public void createIndex(String tableName, String columnName) {
        createIndex(tableName, columnName, "IDX_" + System.currentTimeMillis());
    }

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

                PlanNode plan = createScanPlan(tableName, null);
                plan.open();
                while (true) {
                    Tuple t = plan.next();
                    if (t == null)
                        break;
                    if (ctx.isDeleted(tableName, t.getRid()))
                        continue;
                    if (predicate.test(t)) {
                        ctx.addDelete(tableName, t.getRid());
                        count++;
                    }
                }
                plan.close();

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
        // scan plan would be better but keeping direct logic for now
        // BUT we need to iterate pages of the Correct Table
        Catalog catalog = Catalog.getInstance();
        Schema schema = catalog.getTableSchema(tableName);
        if (schema == null)
            return 0;

        TableStorage ts = getTableStorage(tableName);
        com.sylo.kylo.core.index.IndexManager indexMgr = catalog.getIndexManager();

        int currentPageId = 0;
        int count = 0;

        while (true) {
            try {
                if (currentPageId == StorageConstants.INVALID_PAGE_ID)
                    break;
                com.sylo.kylo.core.storage.Page page = ts.bufferPool
                        .fetchPage(new com.sylo.kylo.core.storage.PageId(currentPageId));
                int cnt = page.getSlotCount();
                boolean dirty = false;
                for (int i = 0; i < cnt; i++) {
                    Tuple t = page.getTuple(i, schema);
                    if (!t.getRowHeader().isDeleted() && predicate.test(t)) {
                        // FK Check disabled likely needed if no ConstraintManager access

                        page.markTupleDeleted(i);
                        count++;
                        dirty = true;

                        for (int c = 0; c < schema.getColumnCount(); c++) {
                            String colName = schema.getColumn(c).getName();
                            if (indexMgr.hasIndex(tableName, colName)) {
                                com.sylo.kylo.core.index.BPlusTreeIndex idx = indexMgr.getIndex(tableName, colName,
                                        ts.bufferPool);
                                if (idx != null) {
                                    idx.delete(t.getValue(c));
                                }
                            }
                        }
                    }
                }
                if (dirty)
                    page.setDirty(true);

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

    public void updateTuple(String tableName, java.util.Map<Integer, Object> changes, Predicate<Tuple> predicate) {
        updateTuple(null, tableName, changes, predicate);
    }

    public void updateTuple(String sessionId, String tableName, java.util.Map<Integer, Object> changes,
            Predicate<Tuple> predicate) {
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

        for (Tuple oldT : toUpdate) {
            final long targetRid = oldT.getRid();
            final Tuple targetObj = oldT;

            deleteTuple(sessionId, tableName, t -> {
                if (targetRid != 0)
                    return t.getRid() == targetRid;
                return t == targetObj;
            });

            Object[] newVals = oldT.getValues().clone();
            for (java.util.Map.Entry<Integer, Object> entry : changes.entrySet()) {
                newVals[entry.getKey()] = entry.getValue();
            }

            insertTuple(sessionId, tableName, newVals);
        }
    }

    public void dropTable(String tableName) {
        TableStorage ts = tableStorage.remove(tableName);
        if (ts != null) {
            ts.close();
        }
        
        // Physical deletion
        String safeName = tableName.replaceAll(":", "_");
        File dbFile = new File(dataDir, safeName + ".db");
        if (dbFile.exists()) {
            boolean deleted = dbFile.delete();
            System.out.println("ExecutionEngine: Physically deleted " + dbFile.getAbsolutePath() + ": " + deleted);
        }
        
        // Also cleanup IndexManager if needed, but it's handled by Catalog usually.
    }

    public synchronized void close() {
        for (TableStorage ts : tableStorage.values()) {
            ts.close();
        }
        tableStorage.clear();
    }
}
