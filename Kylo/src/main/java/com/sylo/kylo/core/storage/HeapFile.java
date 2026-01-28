package com.sylo.kylo.core.storage;

import com.sylo.kylo.core.catalog.Schema;
import com.sylo.kylo.core.structure.Tuple;

public class HeapFile {
    private final BufferPoolManager bufferPool;
    // Simple tracking of pages. Real impl would have a free list header page.
    // For now we just scan or append.
    // To make O(1) insert as requested, we maintain a notion of last page or pages
    // with space.
    // Let's assume we simply track the last allocated page for append.
    private PageId lastPageId;

    public HeapFile(BufferPoolManager bufferPool) {
        this.bufferPool = bufferPool;
        // Correctly handle initialization.
        // If the DB is empty, we MUST allocate Page 0 formally so subsequent logic
        // (like Index creation)
        // respects the file size.
        if (bufferPool.getNumPages() == 0) {
            Page p = bufferPool.newPage();
            this.lastPageId = p.getPageId();
        } else {
            // If DB exists, we assume Page 0 is the start of Heap (legacy/simple mode)
            // In a real system we'd look up the "Last Page" from a header.
            // For now, default to 0 and we'll scan or just overwrite (toy db behavior).
            this.lastPageId = new PageId(0);
        }
    }

    public long insertTuple(Tuple tuple, Schema schema) {
        // Try last page
        Page page = bufferPool.fetchPage(lastPageId);
        int slot = page.insertTuple(tuple, schema);

        if (slot == -1) {
            // Page full, allocate new
            Page newPage = bufferPool.newPage();
            this.lastPageId = newPage.getPageId();

            // Link previous last to this new one (if linked list logic used)
            page.setNextPageId(newPage.getPageId().getPageNumber());
            // Marking dirty? bufferPool.newPage() returns clean-ish page, but we modified
            // 'page' (old last)
            page.setDirty(true);

            // Insert into new
            int newSlot = newPage.insertTuple(tuple, schema);
            if (newSlot == -1) {
                throw new RuntimeException("Tuple too large for a fresh page!");
            }
            return ((long) newPage.getPageId().getPageNumber() << 32) | newSlot;
        }

        return ((long) page.getPageId().getPageNumber() << 32) | slot;
    }
}
