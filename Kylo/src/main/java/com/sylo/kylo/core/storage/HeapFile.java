package com.sylo.kylo.core.storage;

import com.sylo.kylo.core.catalog.Schema;
import com.sylo.kylo.core.structure.Tuple;
import java.util.ArrayList;
import java.util.List;

public class HeapFile {
    private final BufferPoolManager bufferPool;
    // Simple tracking of pages. Real impl would have a free list header page.
    // For now we just scan or append.
    // To make O(1) insert as requested, we maintain a notion of last page or pages with space.
    // Let's assume we simply track the last allocated page for append.
    private PageId lastPageId; 

    public HeapFile(BufferPoolManager bufferPool) {
        this.bufferPool = bufferPool;
        // In reality we should read metadata to find last page.
        // We'll initialize assuming empty or find out (not implemented fully).
        this.lastPageId = new PageId(0); 
    }

    public void insertTuple(Tuple tuple, Schema schema) {
        // Try last page
        Page page = bufferPool.fetchPage(lastPageId);
        int slot = page.insertTuple(tuple, schema);
        
        if (slot == -1) {
            // Page full, allocate new
            Page newPage = bufferPool.newPage();
            this.lastPageId = newPage.getPageId();
            
            // Link previous last to this new one (if linked list logic used)
            page.setNextPageId(newPage.getPageId().getPageNumber());
            // Marking dirty? bufferPool.newPage() returns clean-ish page, but we modified 'page' (old last)
            page.setDirty(true);
            
            // Insert into new
            int newSlot = newPage.insertTuple(tuple, schema);
            if (newSlot == -1) {
                throw new RuntimeException("Tuple too large for a fresh page!");
            }
        }
    }
}
