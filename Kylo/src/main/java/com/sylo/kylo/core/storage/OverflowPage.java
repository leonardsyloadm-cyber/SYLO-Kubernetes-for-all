package com.sylo.kylo.core.storage;

public class OverflowPage extends Page {
    
    public OverflowPage(PageId pageId, byte[] data) {
        super(pageId, data);
    }
    
    public OverflowPage(PageId pageId) {
        super(pageId);
    }
    
    // Overflow logic can be specific:
    // It might just treat the whole "free space" as a data chunk without slots,
    // or use a single slot. 
    // Given the prompt says "Must have pointers to next page (linked list)", 
    // that is handled by 'nextPageId' in the base Page class.
    
    // We can add methods to write raw blobs.
    
    public void writeDataChunk(byte[] chunk) {
        // Implement logic to write a large chunk directly to the free space area
        // bypassing slot logic if desired, or just use use insertTuple with a Blob Tuple.
    }
}
