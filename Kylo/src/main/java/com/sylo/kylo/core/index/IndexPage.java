package com.sylo.kylo.core.index;

import com.sylo.kylo.core.storage.Page;
import com.sylo.kylo.core.storage.StorageConstants;
import java.nio.ByteBuffer;

/**
 * B+ Tree Page.
 * 
 * Layout:
 * [Standard Page Header (24 bytes)]
 * [IndexType (4)] [ParentPageId (4)] [KeyType (4)] [Size (4)] [MaxDegree (4)]
 * [Key Array]
 * [Value Array]
 */
public class IndexPage {
    public enum IndexType {
        INTERNAL, LEAF
    }

    public static final int INDEX_HEADER_SIZE = 20; // Type, Parent, KeyType, Size, MaxDegree
    public static final int OFF_INDEX_TYPE = Page.HEADER_SIZE;
    public static final int OFF_PARENT_ID = OFF_INDEX_TYPE + 4;
    public static final int OFF_KEY_TYPE = OFF_PARENT_ID + 4;
    public static final int OFF_SIZE = OFF_KEY_TYPE + 4;
    public static final int OFF_MAX_DEGREE = OFF_SIZE + 4;
    public static final int DATA_START = OFF_MAX_DEGREE + 4;

    protected Page page;
    protected ByteBuffer buffer;

    public IndexPage(Page page, IndexType type) {
        this.page = page;
        this.buffer = ByteBuffer.wrap(page.getData());

        // Only initialize if creating new (size 0)?
        // Or we assume the caller handles init.
        // For wrapper, we read what's there.
    }

    public void init(IndexType type, int parentId) {
        setIndexType(type);
        setParentPageId(parentId);
        setSize(0);
        // HEURISTIC: Max degree based on space.
        // Space = PAGE_SIZE - DATA_START
        // Leaf: (Key(4) + ROW_ID(8)) * N
        // Internal: (Key(4) + PAGE_ID(4)) * N

        int usable = StorageConstants.PAGE_SIZE - DATA_START;
        if (type == IndexType.LEAF) {
            // 12 bytes per entry
            setMaxDegree(usable / 12);
        } else {
            // 8 bytes per entry
            setMaxDegree(usable / 8);
        }
        page.setDirty(true);
    }

    public IndexType getIndexType() {
        int ordinal = buffer.getInt(OFF_INDEX_TYPE);
        return IndexType.values()[ordinal];
    }

    public void setIndexType(IndexType type) {
        buffer.putInt(OFF_INDEX_TYPE, type.ordinal());
    }

    public int getParentPageId() {
        return buffer.getInt(OFF_PARENT_ID);
    }

    public void setParentPageId(int id) {
        buffer.putInt(OFF_PARENT_ID, id);
    }

    public int getSize() {
        return buffer.getInt(OFF_SIZE);
    }

    public void setSize(int size) {
        buffer.putInt(OFF_SIZE, size);
    }

    public int getMaxDegree() {
        return buffer.getInt(OFF_MAX_DEGREE);
    }

    public void setMaxDegree(int max) {
        buffer.putInt(OFF_MAX_DEGREE, max);
    }

    // --- Key Access ---
    // Keys are stored sequentially starting at DATA_START.
    // keys[0] ... keys[N-1]

    public int getKey(int index) {
        if (index < 0 || index >= getSize())
            throw new IndexOutOfBoundsException();
        return buffer.getInt(DATA_START + (index * 4));
    }

    public void setKey(int index, int key) {
        if (index < 0 || index >= getMaxDegree())
            throw new IndexOutOfBoundsException();
        buffer.putInt(DATA_START + (index * 4), key);
    }

    // --- Value Access ---
    // Values follow keys.
    // Offset = DATA_START + (MaxDegree * 4) + (index * ValueSize)

    private int getValueOffset(int index) {
        int keyAreaSize = getMaxDegree() * 4;
        int valueSize = (getIndexType() == IndexType.LEAF) ? 8 : 4;
        return DATA_START + keyAreaSize + (index * valueSize);
    }

    // LEAF: Value is RID (PageId(4) + Slot(4)) -> Long
    public long getRid(int index) {
        if (getIndexType() != IndexType.LEAF)
            throw new IllegalStateException("Not a Leaf");
        return buffer.getLong(getValueOffset(index));
    }

    public void setRid(int index, long rid) {
        if (getIndexType() != IndexType.LEAF)
            throw new IllegalStateException("Not a Leaf");
        buffer.putLong(getValueOffset(index), rid);
    }

    // INTERNAL: Value is PageId (4)
    public int getPageId(int index) {
        if (getIndexType() != IndexType.INTERNAL)
            throw new IllegalStateException("Not Internal");
        return buffer.getInt(getValueOffset(index));
    }

    public void setPageId(int index, int pageId) {
        if (getIndexType() != IndexType.INTERNAL)
            throw new IllegalStateException("Not Internal");
        buffer.putInt(getValueOffset(index), pageId);
    }

    public Page getPage() {
        return page;
    }
}
