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
import com.sylo.kylo.core.index.BPlusTreeIndex;
import com.sylo.kylo.core.index.Index;
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
    private final Map<String, Index> tableIndices; // TableName.ColumnName -> Index

    public ExecutionEngine(String dbPath) {
        this.diskManager = new DiskManager(dbPath);
        this.bufferPool = new BufferPoolManager(diskManager, 500); // 500 pages cache
        this.tableFiles = new HashMap<>();
        this.tableIndices = new HashMap<>();
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

        // 4. Insert into Heap
        long rid = heapFile.insertTuple(tuple, schema);

        // 5. Insert into Indices (if any)
        // Check for indices on each column
        for (int i = 0; i < schema.getColumnCount(); i++) {
            String colName = schema.getColumn(i).getName();
            String indexKey = tableName + "." + colName;
            if (tableIndices.containsKey(indexKey)) {
                Index idx = tableIndices.get(indexKey);
                idx.insert(values[i], rid);
            }
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

    public void createIndex(String tableName, String columnName) {
        // Create B+ Tree
        // Use a distinct root page ID (e.g., from a meta table or just allocate new)
        // For simplicity: allocate new page for root
        com.sylo.kylo.core.storage.Page root = bufferPool.newPage();
        int rootId = root.getPageId().getPageNumber();

        Index idx = new BPlusTreeIndex(bufferPool, rootId);
        tableIndices.put(tableName + "." + columnName, idx);

        // Populate index from existing data
        PlanNode scan = createScanPlan(tableName, null);
        scan.open();
        Catalog catalog = Catalog.getInstance();
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

        System.out.println("Index created on " + tableName + "." + columnName);
    }

    public int deleteTuple(String tableName, Predicate<Tuple> predicate) {
        PlanNode plan = createScanPlan(tableName, predicate);
        plan.open();
        int count = 0;

        Catalog catalog = Catalog.getInstance();
        Schema schema = catalog.getTableSchema(tableName);
        if (schema == null)
            return 0;

        int currentPageId = 0;
        while (true) {
            try {
                if (currentPageId == StorageConstants.INVALID_PAGE_ID)
                    break;
                com.sylo.kylo.core.storage.Page page = bufferPool
                        .fetchPage(new com.sylo.kylo.core.storage.PageId(currentPageId));
                int cnt = page.getSlotCount();
                for (int i = 0; i < cnt; i++) {
                    Tuple t = page.getTuple(i, schema);
                    if (!t.getRowHeader().isDeleted() && predicate.test(t)) {
                        page.markTupleDeleted(i);
                        count++;
                    }
                }
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
        int deleted = deleteTuple(tableName, predicate);
        if (deleted > 0) {
            insertTuple(tableName, newValues);
        }
    }

    public void close() {
        bufferPool.flushAllPages();
        diskManager.close();
    }
}
