package com.sylo.kylo.core.storage;

import java.io.File;

public class StorageConfig {
    public static final String BASE_DIR;

    static {
        File dockerDataDir = new File("/app/kylo_storage");
        if (dockerDataDir.exists() && dockerDataDir.isDirectory()) {
            BASE_DIR = "/app/kylo_storage";
            System.out.println("🐳 StorageConfig Initialize: Docker Volume Mounted.");
        } else {
            BASE_DIR = "kylo_system/data";
            new File(BASE_DIR).mkdirs();
            System.out.println("💻 StorageConfig Initialize: Local Directory.");
        }
    }
}
