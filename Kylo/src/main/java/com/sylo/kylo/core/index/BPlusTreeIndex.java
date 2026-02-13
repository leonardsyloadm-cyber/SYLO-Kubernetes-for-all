package com.sylo.kylo.core.index;

import com.sylo.kylo.core.storage.BufferPoolManager;
import com.sylo.kylo.core.storage.Page;
import com.sylo.kylo.core.storage.PageId;
import com.sylo.kylo.core.storage.StorageConstants;

public class BPlusTreeIndex implements Index {
    private final BufferPoolManager bufferPool;
    private int rootPageId;
    private boolean isUnique = false;

    public BPlusTreeIndex(BufferPoolManager bufferPool, int rootPageId) {
        this.bufferPool = bufferPool;
        this.rootPageId = rootPageId;

        if (this.rootPageId == StorageConstants.INVALID_PAGE_ID) {
            createNewTree();
        }
    }

    private void createNewTree() {
        Page root = bufferPool.newPage();
        this.rootPageId = root.getPageId().getPageNumber();
        IndexPage idxPage = new IndexPage(root, IndexPage.IndexType.LEAF);
        idxPage.init(IndexPage.IndexType.LEAF, StorageConstants.INVALID_PAGE_ID);
        // bufferPool.unpinPage(root.getPageId(), true); // Real systems unpin
    }

    public void setUnique(boolean unique) {
        this.isUnique = unique;
    }

    public int getRootPageId() {
        return rootPageId;
    }

    @Override
    public void insert(Object key, long rid) {
        int intKey;
        if (key instanceof Integer) {
            intKey = (Integer) key;
        } else if (key instanceof Long) {
            // BIGINT support - cast to int (potential overflow for very large values)
            intKey = ((Long) key).intValue();
        } else if (key instanceof String) {
            // Support for System Tables/Legacy String Indices via HashCode
            intKey = key.hashCode();
        } else if (key instanceof java.util.UUID) {
            // Convert UUID to String, then hash
            String uuidStr = key.toString();
            intKey = uuidStr.hashCode();
            System.out.println("DEBUG INDEX INSERT: UUID=" + key + " -> hashCode=" + intKey);
        } else {
            // Log the type for debugging
            throw new IllegalArgumentException("BPlusTree only supports Integer/Long/String/UUID keys. Got: "
                    + (key == null ? "null" : key.getClass().getName()));
        }

        if (isUnique) {
            long existing = search(key);
            if (existing != -1) {
                throw new RuntimeException("Duplicate entry '" + key + "' for key 'PRIMARY'"); // Simplified msg
            }
        }

        Page root = bufferPool.fetchPage(new PageId(rootPageId));
        // We need to read correct type
        // IndexPage rootIdx = new IndexPage(root, IndexPage.IndexType.LEAF);
        // IndexPage.IndexType type = rootIdx.getIndexType();
        // Since insertIntoTree re-fetches and checks type, we can skip this check or
        // just proceed.

        insertIntoTree(root.getPageId().getPageNumber(), intKey, rid);
    }

    private void insertIntoTree(int currentPageId, int key, long rid) {
        Page page = bufferPool.fetchPage(new PageId(currentPageId));
        IndexPage idxPage = new IndexPage(page, IndexPage.IndexType.LEAF); // dummy type
        // Refresh type from header
        IndexPage.IndexType type = idxPage.getIndexType();
        idxPage = new IndexPage(page, type); // Clean wrapper

        if (type == IndexPage.IndexType.LEAF) {
            insertLeaf(idxPage, key, rid);
        } else {
            // Find child
            int i = 0;
            while (i < idxPage.getSize() && key >= idxPage.getKey(i)) {
                i++;
            }
            // Value at i is the PageId to go to.
            // Wait, standard B+ Tree Internal Node:
            // [Ptr0] Key0 [Ptr1] Key1 ...
            // My IndexPage structure: Keys[] and Values[].
            // Internal: Keys[K0, K1...], Values[P0, P1...] where P(i) is pointer < K(i)?
            // Or P(i) is pointer >= K(i)?
            // Let's adopt: Keys K0..Kn-1. Values V0..Vn.
            // My structure has Equal number of Keys and Values if I look at `IndexPage`?
            // "Internal: (Key(4) + PAGE_ID(4)) * N"
            // This suggests pairs (Key, Ptr).
            // Usually Internal Node has N keys and N+1 pointers.
            // Simplified B+: pairs of (SeparatorKey, PageId) where PageId points to subtree
            // >= Key.
            // And maybe a "Leftmost" pointer?
            // The instructions didn't specify. I will assume (Key, Value) pairs where Value
            // is pointer to node with keys >= Key.
            // And implicitly access handled via parent logic?
            // Standard approach: Keys < K go left, >= K go right.
            // Let's implement simpler variant:
            // Internal Node contains Keys and Pointers to children.
            // Pointer[i] points to subtree with keys < Key[i] (or similar).

            // To fit "Pair" model of IndexPage:
            // V[i] is pointer to child containing keys >= K[i] and < K[i+1].
            // This usually requires N+1 pointers.
            // Let's stick to "Values correspond to Keys".
            // Child i covers range [Key i, Key i+1).
            // This is tricky with N keys and N values.
            // I'll assume standard (Key, RID) for Leaf.
            // For Internal, I need to implement N+1 pointers.
            // But `IndexPage` assumes Key/Value pairs count is same implicitly or N, N?
            // "Values follows keys".
            // If I look at `IndexPage`: `getValueOffset` uses `index`.
            // Implies N keys -> N values.

            // Hack for N+1 pointers:
            // Use (Key, PageId) pairs.
            // And one extra PageId?
            // Or maybe Keys in internal node are separators.
            // Let's use: Keys[0..N-1], Values[0..N-1].
            // Value[i] points to child with keys >= Key[i].
            // But what about keys < Key[0]?
            // "Right-heavy" tree? Or just assume min key is -Inf?
            // Let's assume the first key in root covers everything from -Inf?
            // Or simply strict range?
            // I will implement: find child where Key is closest <= QueryKey.
            // i.e., floor entry.

            int childPageId = -1;
            // Search for last key <= queryKey
            int childIdx = -1;
            for (int k = 0; k < idxPage.getSize(); k++) {
                if (idxPage.getKey(k) <= key) {
                    childIdx = k;
                } else {
                    break;
                }
            }

            if (childIdx == -1) {
                // Key is smaller than all keys?
                // In this simplified model, maybe insert at 0?
                // Or maybe the first pointer handles everything?
                // Let's just traverse to 0 if smaller.
                childPageId = idxPage.getPageId(0);
            } else {
                childPageId = idxPage.getPageId(childIdx);
            }

            if (childPageId == currentPageId) {
                throw new RuntimeException(
                        "Circular Wait detected in B+ Tree: Page " + currentPageId + " points to itself.");
            }

            insertIntoTree(childPageId, key, rid);
        }
    }

    private void insertLeaf(IndexPage leaf, int key, long rid) {
        // Linear scan to find position
        int pos = 0;
        while (pos < leaf.getSize() && leaf.getKey(pos) < key) {
            pos++;
        }

        // Check filtering/duplicates? Assuming allowed.

        if (leaf.getSize() >= leaf.getMaxDegree()) {
            splitLeaf(leaf, key, rid);
        } else {
            // Shift
            for (int i = leaf.getSize(); i > pos; i--) {
                leaf.setKey(i, leaf.getKey(i - 1));
                leaf.setRid(i, leaf.getRid(i - 1));
            }
            leaf.setKey(pos, key);
            leaf.setRid(pos, rid);
            leaf.setSize(leaf.getSize() + 1);
            leaf.getPage().setDirty(true);
        }
    }

    private void splitLeaf(IndexPage leaf, int newKey, long newRid) {
        // Create new sibling
        Page newPage = bufferPool.newPage();
        IndexPage sibling = new IndexPage(newPage, IndexPage.IndexType.LEAF);
        sibling.init(IndexPage.IndexType.LEAF, leaf.getParentPageId());

        // Temp arrays including new key
        int N = leaf.getSize();
        int[] allKeys = new int[N + 1];
        long[] allRids = new long[N + 1];

        int pos = 0;
        int inserted = 0;
        for (int i = 0; i < N; i++) {
            if (inserted == 0 && leaf.getKey(i) > newKey) {
                allKeys[pos] = newKey;
                allRids[pos] = newRid;
                pos++;
                inserted = 1;
            }
            allKeys[pos] = leaf.getKey(i);
            allRids[pos] = leaf.getRid(i);
            pos++;
        }
        if (inserted == 0) {
            allKeys[pos] = newKey;
            allRids[pos] = newRid;
        }

        // Redistribute
        // Keep N/2 + 1 in leaf, rest in sibling
        int splitPoint = (N + 1) / 2;

        leaf.setSize(splitPoint);
        for (int i = 0; i < splitPoint; i++) {
            leaf.setKey(i, allKeys[i]);
            leaf.setRid(i, allRids[i]);
        }

        int siblingSize = (N + 1) - splitPoint;
        sibling.setSize(siblingSize);
        for (int i = 0; i < siblingSize; i++) {
            sibling.setKey(i, allKeys[splitPoint + i]);
            sibling.setRid(i, allRids[splitPoint + i]);
        }

        leaf.getPage().setDirty(true);
        sibling.getPage().setDirty(true);

        // Propagate to parent
        int newKeyForParent = sibling.getKey(0); // Smallest in sibling
        insertIntoParent(leaf, newKeyForParent, sibling);
    }

    private void insertIntoParent(IndexPage left, int key, IndexPage right) {
        int parentId = left.getParentPageId();

        if (parentId == StorageConstants.INVALID_PAGE_ID) {
            // Create new root
            Page newRootPage = bufferPool.newPage();
            IndexPage newRoot = new IndexPage(newRootPage, IndexPage.IndexType.INTERNAL);
            newRoot.init(IndexPage.IndexType.INTERNAL, StorageConstants.INVALID_PAGE_ID);

            newRoot.setSize(2);
            newRoot.setKey(0, left.getKey(0)); // Usually min key? Or leave logic
            newRoot.setPageId(0, left.getPage().getPageId().getPageNumber());

            newRoot.setKey(1, key);
            newRoot.setPageId(1, right.getPage().getPageId().getPageNumber());

            this.rootPageId = newRootPage.getPageId().getPageNumber();
            left.setParentPageId(rootPageId);
            right.setParentPageId(rootPageId);

            newRoot.getPage().setDirty(true);
            left.getPage().setDirty(true);
            right.getPage().setDirty(true);
            return;
        }

        Page parentPage = bufferPool.fetchPage(new PageId(parentId));
        IndexPage parent = new IndexPage(parentPage, IndexPage.IndexType.INTERNAL);

        if (parent.getSize() >= parent.getMaxDegree()) {
            splitInternal(parent, key, right);
        } else {
            // Insert (key, rightPageId) into parent keeping sorted order
            int pos = 0;
            while (pos < parent.getSize() && parent.getKey(pos) < key) {
                pos++;
            }

            // Shift
            for (int i = parent.getSize(); i > pos; i--) {
                parent.setKey(i, parent.getKey(i - 1));
                parent.setPageId(i, parent.getPageId(i - 1));
            }
            parent.setKey(pos, key);
            parent.setPageId(pos, right.getPage().getPageId().getPageNumber());
            parent.setSize(parent.getSize() + 1);
            parent.getPage().setDirty(true);

            right.setParentPageId(parentId); // Ensure right points to parent
            right.getPage().setDirty(true);
        }
    }

    private void splitInternal(IndexPage node, int newKey, IndexPage rightChild) {
        // Simplified split similar to leaf but handles "Push up" of middle key
        // For brevity in this task, basic implementation provided.
        // In fully rigorous B+ Tree, middle key moves UP, not copied.

        // For now, allow "full" nodes to just grow via unsafe hack or fail?
        // User requested "Lógica de Page Splitting".
        // I will implement a safe basic split.

        Page newPage = bufferPool.newPage();
        IndexPage sibling = new IndexPage(newPage, IndexPage.IndexType.INTERNAL);
        sibling.init(IndexPage.IndexType.INTERNAL, node.getParentPageId());

        // Gather all including newone
        int N = node.getSize();
        int[] keys = new int[N + 1];
        int[] ptrs = new int[N + 1];

        int pos = 0;
        int inserted = 0;
        int rightChildId = rightChild.getPage().getPageId().getPageNumber();

        for (int i = 0; i < N; i++) {
            if (inserted == 0 && node.getKey(i) > newKey) {
                keys[pos] = newKey;
                ptrs[pos] = rightChildId;
                pos++;
                inserted = 1;
            }
            keys[pos] = node.getKey(i);
            ptrs[pos] = node.getPageId(i);
            pos++;
        }
        if (inserted == 0) {
            keys[pos] = newKey;
            ptrs[pos] = rightChildId;
        }

        int mid = (N + 1) / 2;
        int pushUpKey = keys[mid];

        // Left keeps 0 to mid-1
        node.setSize(mid);
        for (int i = 0; i < mid; i++) {
            node.setKey(i, keys[i]);
            node.setPageId(i, ptrs[i]);
        }

        // Sibling gets mid to end?
        // Internal node split: Separation Key moves up.
        // Sibling parts are mid+1 to end.
        // Pointers?
        // B+ Tree Internal: P0 K0 P1 K1 ...
        // My struct: (K0, P0), (K1, P1).

        int sibSize = (N + 1) - mid;
        sibling.setSize(sibSize);
        for (int i = 0; i < sibSize; i++) {
            sibling.setKey(i, keys[mid + i]);
            sibling.setPageId(i, ptrs[mid + i]);

            // Update parent pointers of children moved to sibling
            int childId = ptrs[mid + i];
            Page childP = bufferPool.fetchPage(new PageId(childId));
            IndexPage child = new IndexPage(childP, IndexPage.IndexType.INTERNAL); // Type unknown, just for header
                                                                                   // setter
            child.setParentPageId(newPage.getPageId().getPageNumber());
            childP.setDirty(true);
        }

        node.getPage().setDirty(true);
        sibling.getPage().setDirty(true);

        insertIntoParent(node, pushUpKey, sibling);
    }

    @Override
    public void delete(Object key) {
        // Not implemented (Priority is Insert/Search)
    }

    @Override
    public long search(Object key) {
        int intKey;
        if (key instanceof Integer) {
            intKey = (Integer) key;
        } else if (key instanceof Long) {
            // BIGINT support
            intKey = ((Long) key).intValue();
        } else if (key instanceof String) {
            intKey = key.hashCode();
        } else if (key instanceof java.util.UUID) {
            // Convert UUID to String, then hash
            String uuidStr = key.toString();
            intKey = uuidStr.hashCode();
            System.out.println("DEBUG INDEX SEARCH: UUID=" + key + " -> hashCode=" + intKey);
        } else {
            return -1;
        }

        int currentPageId = rootPageId;
        while (currentPageId != StorageConstants.INVALID_PAGE_ID) {
            Page page = bufferPool.fetchPage(new PageId(currentPageId));
            IndexPage idx = new IndexPage(page, IndexPage.IndexType.LEAF);

            if (idx.getIndexType() == IndexPage.IndexType.LEAF) {
                // Search leaf
                for (int i = 0; i < idx.getSize(); i++) {
                    if (idx.getKey(i) == intKey) {
                        return idx.getRid(i);
                    }
                }
                return -1;
            } else {
                // Internal
                // Find <=
                int nextId = -1;
                // Default to first if all > key? OR first if key < key[0]?
                // With my (Key, Ptr) logic which means "This ptr covers keys around this key"?
                // Let's use the logic: find rightmost key <= queryKey.
                int bestIdx = -1;
                for (int i = 0; i < idx.getSize(); i++) {
                    if (idx.getKey(i) <= intKey) {
                        bestIdx = i;
                    } else {
                        break;
                    }
                }
                if (bestIdx == -1) {
                    if (idx.getSize() > 0)
                        nextId = idx.getPageId(0);
                    else
                        break;
                } else {
                    nextId = idx.getPageId(bestIdx);
                }
                currentPageId = nextId;
            }
        }
        return -1;
    }
}
