package com.sylo.kylo.core.storage;

import com.sylo.kylo.core.structure.Tuple;
import com.sylo.kylo.core.catalog.Schema;
import java.nio.ByteBuffer;

/**
 * Clase Page (La unidad atómica de I/O):
 * 
 * Header Format:
 * [LSN (8)] [PageId (4)] [FreeSpacePtr (4)] [SlotCount (4)] [NextPageId (4) -
 * for overflow chains]
 * 
 * Header Size = 24 bytes.
 * 
 * Slotted Page Layout:
 * [Header] [SlotArray -> ] ... free space ... [ <- Tuple Data]
 * 
 * SlotArray Entry:
 * [Offset (4)] [Length (4)]
 */
public class Page {
    public static final int HEADER_SIZE = 24;

    private PageId pageId;
    private byte[] data;
    private boolean isDirty;

    // Header fields in memory
    private long lsn;
    // pageID is already stored in object
    private int freeSpacePtr; // Points to the end of the page (start of free space from bottom)
    private int slotCount;
    private int nextPageId;

    public Page(PageId pageId, byte[] data) {
        this.pageId = pageId;
        this.data = data;
        this.isDirty = false;

        if (data.length != StorageConstants.PAGE_SIZE) {
            throw new IllegalArgumentException("Invalid page size");
        }

        readHeader();
    }

    public Page(PageId pageId) {
        this(pageId, new byte[StorageConstants.PAGE_SIZE]);
        // Initialize empty page
        this.lsn = 0;
        this.freeSpacePtr = StorageConstants.PAGE_SIZE;
        this.slotCount = 0;
        this.nextPageId = StorageConstants.INVALID_PAGE_ID;
        writeHeader();
    }

    public void readHeader() {
        ByteBuffer b = ByteBuffer.wrap(data);
        this.lsn = b.getLong();
        // Skip pageId in file if redundant, but good to check or store
        int storedPageNum = b.getInt();
        if (storedPageNum != pageId.getPageNumber() && storedPageNum != 0) {
            // In a real system we might validate this, but for new pages it might be 0
        }
        this.freeSpacePtr = b.getInt();
        this.slotCount = b.getInt();
        this.nextPageId = b.getInt();
    }

    // Alias for external callers (e.g. BufferPool after disk read)
    public void refresh() {
        readHeader();
    }

    private void writeHeader() {
        ByteBuffer b = ByteBuffer.wrap(data);
        b.putLong(lsn);
        b.putInt(pageId.getPageNumber());
        b.putInt(freeSpacePtr);
        b.putInt(slotCount);
        b.putInt(nextPageId);
    }

    /**
     * Insert a tuple into this page.
     * Returns the slot index or -1 if no space.
     */
    public int insertTuple(Tuple tuple, Schema schema) {
        byte[] tupleData = tuple.serialize(schema);
        int requiredSpace = tupleData.length + 8; // Data + Slot Entry (8 bytes)

        // Calculate available free space
        // Free Space = FreeSpacePtr - (HeaderSize + SlotCount * 8)
        int headerEnd = HEADER_SIZE + (slotCount * 8);
        int available = freeSpacePtr - headerEnd;

        if (requiredSpace > available) {
            return -1;
        }

        // Insert data at the end (prepending to current data area)
        int insertOffset = freeSpacePtr - tupleData.length;
        System.arraycopy(tupleData, 0, data, insertOffset, tupleData.length);

        // Update pointers
        freeSpacePtr = insertOffset;

        // Add slot entry
        ByteBuffer b = ByteBuffer.wrap(data);
        b.position(headerEnd);
        b.putInt(insertOffset);
        b.putInt(tupleData.length);

        slotCount++;
        writeHeader();
        isDirty = true;

        return slotCount - 1;
    }

    public Tuple getTuple(int slotIndex, Schema schema) {
        if (slotIndex < 0 || slotIndex >= slotCount) {
            throw new IllegalArgumentException("Invalid slot index: " + slotIndex);
        }

        ByteBuffer b = ByteBuffer.wrap(data);
        int slotEntryPos = HEADER_SIZE + (slotIndex * 8);
        b.position(slotEntryPos);
        int offset = b.getInt();
        int length = b.getInt();

        byte[] tupleData = new byte[length];
        System.arraycopy(data, offset, tupleData, 0, length);

        return Tuple.deserialize(tupleData, schema);
    }

    public void markTupleDeleted(int slotIndex) {
        if (slotIndex < 0 || slotIndex >= slotCount) {
            throw new IllegalArgumentException("Invalid slot index: " + slotIndex);
        }

        ByteBuffer b = ByteBuffer.wrap(data);
        int slotEntryPos = HEADER_SIZE + (slotIndex * 8);
        b.position(slotEntryPos);
        int offset = b.getInt();
        // RowHeader starts at offset. Flags are at offset + 16 (xmin(8) + xmax(8))
        int flagsPos = offset + 16;
        b.position(flagsPos);
        short flags = b.getShort();
        flags |= 1; // Set deleted bit
        b.position(flagsPos);
        b.putShort(flags);

        isDirty = true;
    }

    // ... Getters/Setters for basic props ...

    public PageId getPageId() {
        return pageId;
    }

    public byte[] getData() {
        return data;
    }

    public boolean isDirty() {
        return isDirty;
    }

    public void setDirty(boolean dirty) {
        isDirty = dirty;
    }

    public int getNextPageId() {
        return nextPageId;
    }

    public void setNextPageId(int nextPageId) {
        this.nextPageId = nextPageId;
        writeHeader();
        isDirty = true;
    }

    public int getFreeSpace() {
        int headerEnd = HEADER_SIZE + (slotCount * 8);
        return freeSpacePtr - headerEnd;
    }

    public int getSlotCount() {
        return slotCount;
    }
}
