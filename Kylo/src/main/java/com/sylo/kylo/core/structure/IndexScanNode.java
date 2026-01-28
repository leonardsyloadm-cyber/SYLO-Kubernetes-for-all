package com.sylo.kylo.core.structure;

import com.sylo.kylo.core.catalog.Schema;
import com.sylo.kylo.core.index.BPlusTreeIndex;
import com.sylo.kylo.core.storage.BufferPoolManager;
import com.sylo.kylo.core.storage.Page;
import com.sylo.kylo.core.storage.PageId;

public class IndexScanNode extends PlanNode {
    private final BufferPoolManager bufferPool;
    private final BPlusTreeIndex index;
    private final Object searchKey;
    private final Schema schema;
    private boolean executed = false;

    public IndexScanNode(BufferPoolManager bufferPool, Schema schema, BPlusTreeIndex index, Object searchKey) {
        this.bufferPool = bufferPool;
        this.schema = schema;
        this.index = index;
        this.searchKey = searchKey;
    }

    @Override
    public void open() {
        executed = false;
    }

    @Override
    public Tuple next() {
        if (executed) {
            return null;
        }

        long rid = index.search(searchKey);
        executed = true; // For now, single access

        if (rid == -1) {
            return null;
        }

        // Fetch Tuple by RID
        int pageId = (int) (rid >> 32);
        int slotId = (int) (rid & 0xFFFFFFFFL);

        try {
            Page page = bufferPool.fetchPage(new PageId(pageId));
            Tuple t = page.getTuple(slotId, schema);
            if (t.getRowHeader().isDeleted()) {
                return null;
            }
            return t;
        } catch (Exception e) {
            e.printStackTrace();
            return null;
        }
    }

    @Override
    public void close() {
        // Nothing to close for now
    }
}
