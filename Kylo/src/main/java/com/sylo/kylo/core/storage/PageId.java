package com.sylo.kylo.core.storage;

import java.util.Objects;

public class PageId {
    private final int pageNumber;

    public PageId(int pageNumber) {
        this.pageNumber = pageNumber;
    }

    public int getPageNumber() {
        return pageNumber;
    }

    public boolean isValid() {
        return pageNumber != StorageConstants.INVALID_PAGE_ID;
    }

    @Override
    public boolean equals(Object o) {
        if (this == o) return true;
        if (o == null || getClass() != o.getClass()) return false;
        PageId pageId = (PageId) o;
        return pageNumber == pageId.pageNumber;
    }

    @Override
    public int hashCode() {
        return Objects.hash(pageNumber);
    }
    
    @Override
    public String toString() {
        return "PageId{" + pageNumber + "}";
    }
}
