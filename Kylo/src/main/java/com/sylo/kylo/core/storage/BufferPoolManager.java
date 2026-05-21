package com.sylo.kylo.core.storage;

import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.locks.ReentrantReadWriteLock;

public class BufferPoolManager {
    private final DiskManager diskManager;
    private final String tableName;
    
    // Global Static Cache across all BufferPoolManager instances
    private static final int MAX_GLOBAL_PAGES = 5000; // Limit system memory
    
    private static class CachedPage {
        final Page page;
        final DiskManager diskManager;
        final ReentrantReadWriteLock lock = new ReentrantReadWriteLock();
        
        CachedPage(Page page, DiskManager diskManager) {
            this.page = page;
            this.diskManager = diskManager;
        }
    }
    
    private static final ConcurrentHashMap<String, CachedPage> globalPageTable = new ConcurrentHashMap<>();

    public BufferPoolManager(String tableName, DiskManager diskManager) {
        this.tableName = tableName;
        this.diskManager = diskManager;
    }

    private String getGlobalKey(PageId pageId) {
        return tableName + ":" + pageId.getPageNumber();
    }

    public Page fetchPage(PageId pageId) {
        String key = getGlobalKey(pageId);
        
        CachedPage cp = globalPageTable.computeIfAbsent(key, k -> {
            evictIfNeeded();
            Page newPage = new Page(pageId);
            diskManager.readPage(pageId, newPage);
            newPage.refresh();
            return new CachedPage(newPage, diskManager);
        });
        
        return cp.page;
    }

    public Page newPage() {
        PageId pageId = diskManager.allocatePage();
        String key = getGlobalKey(pageId);
        
        evictIfNeeded();
        Page page = new Page(pageId);
        CachedPage cp = new CachedPage(page, diskManager);
        globalPageTable.put(key, cp);
        
        return page;
    }

    private void evictIfNeeded() {
        if (globalPageTable.size() >= MAX_GLOBAL_PAGES) {
            // Approximate LRU via random eviction to avoid blocking
            for (Map.Entry<String, CachedPage> entry : globalPageTable.entrySet()) {
                CachedPage cp = entry.getValue();
                if (cp.lock.writeLock().tryLock()) {
                    try {
                        if (cp.page != null && cp.page.isDirty()) {
                            cp.diskManager.writePage(cp.page.getPageId(), cp.page);
                            cp.page.setDirty(false);
                        }
                        globalPageTable.remove(entry.getKey());
                        return; // Evicted one page
                    } finally {
                        cp.lock.writeLock().unlock();
                    }
                }
            }
        }
    }

    public void flushPage(PageId pageId) {
        String key = getGlobalKey(pageId);
        CachedPage cp = globalPageTable.get(key);
        if (cp != null) {
            cp.lock.writeLock().lock();
            try {
                if (cp.page != null && cp.page.isDirty()) {
                    cp.diskManager.writePage(pageId, cp.page);
                    cp.page.setDirty(false);
                }
            } finally {
                cp.lock.writeLock().unlock();
            }
        }
    }

    public void flushAllPages() {
        for (CachedPage cp : globalPageTable.values()) {
            cp.lock.writeLock().lock();
            try {
                if (cp.page != null && cp.page.isDirty()) {
                    cp.diskManager.writePage(cp.page.getPageId(), cp.page);
                    cp.page.setDirty(false);
                }
            } finally {
                cp.lock.writeLock().unlock();
            }
        }
    }

    public int getNumPages() {
        return diskManager.getNumPages();
    }
}
