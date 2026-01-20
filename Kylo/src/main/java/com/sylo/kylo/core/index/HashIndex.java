package com.sylo.kylo.core.index;

public class HashIndex implements Index {
    
    @Override
    public void insert(Object key, long rid) {
        // Extensible hashing implementation
    }

    @Override
    public void delete(Object key) {
        
    }

    @Override
    public long search(Object key) {
        return -1;
    }
}
