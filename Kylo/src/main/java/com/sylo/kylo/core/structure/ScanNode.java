package com.sylo.kylo.core.structure;

import com.sylo.kylo.core.catalog.Schema;
import com.sylo.kylo.core.storage.BufferPoolManager;
import com.sylo.kylo.core.storage.Page;
import com.sylo.kylo.core.storage.PageId;
import com.sylo.kylo.core.storage.StorageConstants;

public class ScanNode extends PlanNode {
    private BufferPoolManager bufferPool;
    private Schema schema;
    private int startPageId;

    // State
    private int currentPageId;
    private Page currentPage;
    private int currentSlot;

    public ScanNode(BufferPoolManager bufferPool, Schema schema, int startPageId) {
        this.bufferPool = bufferPool;
        this.schema = schema;
        this.startPageId = startPageId;
    }

    @Override
    public void open() {
        currentPageId = startPageId;
        currentPage = null;
        currentSlot = 0;
    }

    @Override
    public Tuple next() {
        while (true) {
            if (currentPageId == StorageConstants.INVALID_PAGE_ID) {
                return null;
            }

            if (currentPage == null) {
                try {
                    currentPage = bufferPool.fetchPage(new PageId(currentPageId));
                    currentSlot = 0;
                } catch (Exception e) {
                    return null;
                }
            }

            if (currentSlot < currentPage.getSlotCount()) {
                Tuple t = null;
                try {
                    t = currentPage.getTuple(currentSlot, schema);
                } catch (Exception e) {
                    // ignore error
                }
                currentSlot++;

                if (t != null && !t.getRowHeader().isDeleted()) {
                    t.setRid(((long) currentPageId << 32) | (currentSlot - 1));
                    return t;
                }
            } else {
                // Next page
                int next = currentPage.getNextPageId();
                if (next == currentPageId || next == 0) {
                    next = StorageConstants.INVALID_PAGE_ID;
                }
                currentPageId = next;
                currentPage = null;
                // Loop will handle fetch
            }
        }
    }

    @Override
    public void close() {
        // cleanup if needed
    }
}
