package com.sylo.kylo.core.index;

public interface Index {
    // Record ID usually involves PageId + Slot. Simplifying to just Object key for now.
    void insert(Object key, long rid); // rid as long (pageId << 32 | slot)?
    void delete(Object key);
    long search(Object key);
}
