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

    public void insertTuple(String tableName, Object[] values) {
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
    private void deleteTupleByRid(String tableName, long rid) {
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
        PlanNode plan = createScanPlan(tableName, null);
        List<Object[]> results = new ArrayList<>();
        plan.open();
        try {
            while (true) {
                Tuple t = plan.next();
                if (t == null)
                    break;
                results.add(t.getValues());
            }
        } finally {
            plan.close();
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

    public int deleteTuple(String tableName, Predicate<Tuple> predicate) {
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
                                    // TODO: Implement BPlusTree delete
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

    public void updateTuple(String tableName, Object[] newValues, Predicate<Tuple> predicate) {
        // Naive Update: Delete + Insert
        // This is transactionally safe IF delete and insert are atomic.
        // Currently they are separate operations.
        // Ideally we grab locks.

        // For now, simple execution:
        int deleted = deleteTuple(tableName, predicate);
        if (deleted > 0) {
            // Re-insert 'deleted' times?
            // "updateTuple" typically updates ALL matching rows.
            // If we deleted N rows, we might need to insert N rows?
            // The signature `Object[] newValues` implies setting to CONSTANT values.
            // e.g. UPDATE t SET c=5 WHERE ...
            // So yes, we insert N copies.
            for (int i = 0; i < deleted; i++) {
                insertTuple(tableName, newValues);
            }
        }
    }

    public void close() {
        bufferPool.flushAllPages();
        diskManager.close();
    }
}
