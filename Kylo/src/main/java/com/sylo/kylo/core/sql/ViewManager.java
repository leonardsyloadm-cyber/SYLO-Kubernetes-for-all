package com.sylo.kylo.core.sql;

import java.io.*;
import java.util.HashMap;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

public class ViewManager {
    private static ViewManager instance;
    private final Map<String, String> views; // viewName -> viewDefinition (SQL)
    private final String metaFilePath = "kylo_system/views/views.dat";

    private ViewManager() {
        this.views = new ConcurrentHashMap<>();
        loadViews();
    }

    public static synchronized ViewManager getInstance() {
        if (instance == null) {
            instance = new ViewManager();
        }
        return instance;
    }

    public void createView(String name, String definition) {
        views.put(name, definition);
        saveViews();
    }

    public void dropView(String name) {
        views.remove(name);
        saveViews();
    }

    public String getViewDefinition(String name) {
        return views.get(name);
    }

    public boolean isView(String name) {
        return views.containsKey(name);
    }

    public Map<String, String> getAllViews() {
        return new HashMap<>(views);
    }

    private void loadViews() {
        File file = new File(metaFilePath);
        if (!file.exists())
            return;
        try (DataInputStream dis = new DataInputStream(new FileInputStream(file))) {
            int count = dis.readInt();
            for (int i = 0; i < count; i++) {
                String name = dis.readUTF();
                String def = dis.readUTF();
                views.put(name, def);
            }
        } catch (IOException e) {
            e.printStackTrace();
        }
    }

    private void saveViews() {
        File dir = new File("kylo_system/views");
        if (!dir.exists())
            dir.mkdirs();

        try (DataOutputStream dos = new DataOutputStream(new FileOutputStream(metaFilePath))) {
            dos.writeInt(views.size());
            for (Map.Entry<String, String> entry : views.entrySet()) {
                dos.writeUTF(entry.getKey());
                dos.writeUTF(entry.getValue());
            }
        } catch (IOException e) {
            e.printStackTrace();
        }
    }
}
