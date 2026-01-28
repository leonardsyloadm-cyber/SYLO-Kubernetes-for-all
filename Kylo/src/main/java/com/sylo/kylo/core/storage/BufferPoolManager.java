package com.sylo.kylo.core.storage;

import java.util.LinkedHashMap;
import java.util.Map;

public class BufferPoolManager {
    private final DiskManager diskManager;
    private final int poolSize;
    // Map PageId -> Page. Access order true for LRU.
    private final LinkedHashMap<PageId, Page> pageTable;

    public BufferPoolManager(DiskManager diskManager, int poolSize) {
        this.diskManager = diskManager;
        this.poolSize = poolSize;
        this.pageTable = new LinkedHashMap<PageId, Page>(poolSize, 0.75f, true) {
            @Override
            protected boolean removeEldestEntry(Map.Entry<PageId, Page> eldest) {
                // Determine if we need to evict
                if (size() > BufferPoolManager.this.poolSize) {
                    flushPage(eldest.getKey());
                    return true;
                }
                return false;
            }
        };
    }

    public Page fetchPage(PageId pageId) {
        if (pageTable.containsKey(pageId)) {
            return pageTable.get(pageId);
        }

        // Not in cache, read from disk
        // LRU eviction happens automatically via put if full, triggering
        // removeEldestEntry

        Page page = new Page(pageId); // Empty shell container
        diskManager.readPage(pageId, page);
        page.refresh(); // Sync header fields from loaded data

        // This 'put' might trigger eviction if we are at capacity
        pageTable.put(pageId, page);

        return page;
    }

    public Page newPage() {
        // Allocate space on disk
        PageId pageId = diskManager.allocatePage();
        Page page = new Page(pageId);

        // This 'put' might trigger eviction
        pageTable.put(pageId, page);

        return page;
    }

    public void flushPage(PageId pageId) {
        Page page = pageTable.get(pageId);
        if (page != null && page.isDirty()) {
            diskManager.writePage(pageId, page);
            page.setDirty(false);
        }
    }

    public void flushAllPages() {
        System.out.println("DEBUG: Flushing all pages. Count: " + pageTable.size());
        // Avoid CME by copying keys
        for (PageId pid : new java.util.ArrayList<>(pageTable.keySet())) {
            flushPage(pid);
        }
    }

    public int getNumPages() {
        return diskManager.getNumPages();
    }
}
