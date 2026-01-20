package com.sylo.kylo.core.execution;

import com.sylo.kylo.core.catalog.Catalog;
import com.sylo.kylo.core.catalog.Schema;
import com.sylo.kylo.core.storage.BufferPoolManager;
import com.sylo.kylo.core.storage.DiskManager;
import com.sylo.kylo.core.storage.HeapFile;
import com.sylo.kylo.core.structure.RowHeader;
import com.sylo.kylo.core.structure.Tuple;
import com.sylo.kylo.core.storage.Page;
import java.util.HashMap;
import java.util.Map;

public class ExecutionEngine {
    private final DiskManager diskManager;
    private final BufferPoolManager bufferPool;
    private final Map<String, HeapFile> tableFiles;

    public ExecutionEngine(String dbPath) {
        this.diskManager = new DiskManager(dbPath);
        this.bufferPool = new BufferPoolManager(diskManager, 100); // 100 pages cache
        this.tableFiles = new HashMap<>();
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
            if (val != null) { // if null is allowed check schema.getColumn(i).isNullable()
                schema.getColumn(i).getType().validate(val);
            } else if (!schema.getColumn(i).isNullable()) {
                throw new IllegalArgumentException("Column " + schema.getColumn(i).getName() + " cannot be null.");
            }
        }
        
        // 2. Build Tuple
        RowHeader header = new RowHeader(); // Defaults xmin=0, xmax=0
        Tuple tuple = new Tuple(header, values);
        
        // 3. Get/Create HeapFile for table
        // Ideally Catalog stores mapping to physical file or pageId start.
        // Simplified: One HeapFile object per table name in memory map.
        HeapFile heapFile = tableFiles.computeIfAbsent(tableName, k -> new HeapFile(bufferPool));
        
        // 4. Insert
        heapFile.insertTuple(tuple, schema);
        
        // In a real transactional system, we wouldn't flush immediately, 
        // but for safety in this phase:
        // bufferPool.flushAllPages(); 
    }
    
    public java.util.List<Object[]> scanTable(String tableName) {
        // HeapFile file = tableFiles.get(tableName);
        // if (file == null) return java.util.Collections.emptyList();
        
        // Ensure we can scan even if not recently inserted (Cold Start)
        // We assume Page 0 exists if table is registered in Catalog.
        // Or we should check if file exists? Using DiskManager?
        // For now, let's just proceed to try reading Page 0.
        
        java.util.List<Object[]> results = new java.util.ArrayList<>();
        Catalog catalog = Catalog.getInstance();
        Schema schema = catalog.getTableSchema(tableName);
        if (schema == null) return results; // Table not in catalog
        
        // We need to iterate efficiently.
        // For now, let's just assume we start at Page 0 and follow nextPageId if implemented,
        // or just read known pages if we tracked them.
        // Since HeapFile.java didn't fully implement a page directory, let's try reading Page 0.
        
        // LIMITATION: This implementation only assumes 1 page or linked pages if nextPageId works.
        // We will improve this by simply iterating page 0. If you added more pages, this needs better logic.
        
        int currentPageId = 0;
        while (true) {
             Page page = null;
             try {
                // If invalid, stop
                if (currentPageId == com.sylo.kylo.core.storage.StorageConstants.INVALID_PAGE_ID) break;
                
                // Fetch page
                page = bufferPool.fetchPage(new com.sylo.kylo.core.storage.PageId(currentPageId));
             } catch(Exception e) { break; } 
             
             // Iterate tuples in page
             int count = page.getSlotCount();
             for (int i = 0; i < count; i++) {
                 try {
                    Tuple t = page.getTuple(i, schema);
                    results.add(t.getValues());
                 } catch(Exception e) {
                     // Deleted or invalid tuple?
                 }
             }
             
             // Move to next page
             int next = page.getNextPageId();
             if (next == currentPageId || next == 0 || next == com.sylo.kylo.core.storage.StorageConstants.INVALID_PAGE_ID) {
                 // Prevent infinite loop if logic implies 0 is next or something, 
                 // usually next should be different.
                 // In our simplified impl, default next is -1 (INVALID).
                 break;
             }
             currentPageId = next;
        }
        return results;
    }
    
    public void close() {
        bufferPool.flushAllPages();
        diskManager.close();
    }
}
