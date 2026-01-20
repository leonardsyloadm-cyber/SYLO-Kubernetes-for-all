package com.sylo.kylo.core.storage;

import java.io.File;
import java.io.IOException;
import java.io.RandomAccessFile;

public class DiskManager {
    private RandomAccessFile dbFile;

    public DiskManager(String dbPath) {
        try {
            File f = new File(dbPath);
            if (!f.exists()) {
                f.createNewFile();
            }
            this.dbFile = new RandomAccessFile(f, "rw");
        } catch (IOException e) {
            throw new RuntimeException("Could not open DB file: " + dbPath, e);
        }
    }

    public void readPage(PageId pageId, Page page) {
        try {
            int offset = pageId.getPageNumber() * StorageConstants.PAGE_SIZE;
            if (offset + StorageConstants.PAGE_SIZE > dbFile.length()) {
                return;
            }
            dbFile.seek(offset);
            dbFile.readFully(page.getData());
        } catch (IOException e) {
            throw new RuntimeException("Error reading page " + pageId, e);
        }
    }

    public void writePage(PageId pageId, Page page) {
        try {
            int offset = pageId.getPageNumber() * StorageConstants.PAGE_SIZE;
            dbFile.seek(offset);
            dbFile.write(page.getData());
        } catch (IOException e) {
            throw new RuntimeException("Error writing page " + pageId, e);
        }
    }

    public PageId allocatePage() {
        try {
            long len = dbFile.length();
            int pageNum = (int) (len / StorageConstants.PAGE_SIZE);
            // Extend file
            dbFile.setLength(len + StorageConstants.PAGE_SIZE);
            return new PageId(pageNum);
        } catch (IOException e) {
            throw new RuntimeException("Could not allocate page", e);
        }
    }

    public void close() {
        try {
            dbFile.close();
        } catch (IOException e) {
            e.printStackTrace();
        }
    }
}
