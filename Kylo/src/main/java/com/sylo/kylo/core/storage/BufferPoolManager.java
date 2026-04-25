package com.sylo.kylo.core.storage;

import java.util.LinkedHashMap;
import java.util.Map;
import java.util.ArrayList;

public class BufferPoolManager {
    private final DiskManager diskManager;
    private final String tableName;
    
    // Global Static Cache across all BufferPoolManager instances
    private static final int MAX_GLOBAL_PAGES = 5000; // Limit system memory
    
    private static class CachedPage {
        final Page page;
        final DiskManager diskManager;
        CachedPage(Page page, DiskManager diskManager) {
            this.page = page;
            this.diskManager = diskManager;
        }
    }
    
    private static final LinkedHashMap<String, CachedPage> globalPageTable = 
            new LinkedHashMap<String, CachedPage>(MAX_GLOBAL_PAGES, 0.75f, true) {
        @Override
        protected boolean removeEldestEntry(Map.Entry<String, CachedPage> eldest) {
            if (size() > MAX_GLOBAL_PAGES) {
                CachedPage cp = eldest.getValue();
                if (cp.page != null && cp.page.isDirty()) {
                    cp.diskManager.writePage(cp.page.getPageId(), cp.page);
                    cp.page.setDirty(false);
                }
                return true;
            }
            return false;
        }
    };

    public BufferPoolManager(String tableName, DiskManager diskManager) {
        this.tableName = tableName;
        this.diskManager = diskManager;
    }

    private String getGlobalKey(PageId pageId) {
        return tableName + ":" + pageId.getPageNumber();
    }

    public Page fetchPage(PageId pageId) {
        String key = getGlobalKey(pageId);
        synchronized(globalPageTable) {
            CachedPage cp = globalPageTable.get(key);
            if (cp != null) {
                return cp.page;
            }
        }
        
        Page page = new Page(pageId);
        diskManager.readPage(pageId, page);
        page.refresh();
        
        synchronized(globalPageTable) {
            globalPageTable.put(key, new CachedPage(page, diskManager));
        }
        return page;
    }

    public Page newPage() {
        PageId pageId = diskManager.allocatePage();
        Page page = new Page(pageId);
        synchronized(globalPageTable) {
            globalPageTable.put(getGlobalKey(pageId), new CachedPage(page, diskManager));
        }
        return page;
    }

    public void flushPage(PageId pageId) {
        String key = getGlobalKey(pageId);
        synchronized(globalPageTable) {
            CachedPage cp = globalPageTable.get(key);
            if (cp != null && cp.page.isDirty()) {
                diskManager.writePage(pageId, cp.page);
                cp.page.setDirty(false);
            }
        }
    }

    public void flushAllPages() {
        synchronized(globalPageTable) {
            for (CachedPage cp : new ArrayList<>(globalPageTable.values())) {
                if (cp.page != null && cp.page.isDirty()) {
                    cp.diskManager.writePage(cp.page.getPageId(), cp.page);
                    cp.page.setDirty(false);
                }
            }
        }
    }

    public int getNumPages() {
        return diskManager.getNumPages();
    }
}
