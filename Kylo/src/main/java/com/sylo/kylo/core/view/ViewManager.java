package com.sylo.kylo.core.view;

import java.io.*;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

@SuppressWarnings("unused") // Future feature: View management
public class ViewManager {
    private static ViewManager instance;
    private Map<String, View> views;
    private static final String STORAGE_PATH = "kylo_system/settings/views.dat";

    private ViewManager() {
        views = new ConcurrentHashMap<>();
        load();
    }

    public static synchronized ViewManager getInstance() {
        if (instance == null) {
            instance = new ViewManager();
        }
        return instance;
    }

    public void addView(View v) {
        views.put(generateKey(v.getDatabase(), v.getName()), v);
        save();
        System.out.println("View added: " + v);
    }

    public View getView(String db, String name) {
        return views.get(generateKey(db, name));
    }

    public void dropView(String db, String name) {
        views.remove(generateKey(db, name));
        save();
    }

    public Map<String, View> getAllViews() {
        return views;
    }

    private String generateKey(String db, String name) {
        return db.toLowerCase() + "." + name.toLowerCase();
    }

    @SuppressWarnings("unchecked")
    private void load() {
        File f = new File(STORAGE_PATH);
        if (!f.exists())
            return;
        try (ObjectInputStream ois = new ObjectInputStream(new FileInputStream(f))) {
            views = (Map<String, View>) ois.readObject();
        } catch (Exception e) {
            System.err.println("Error loading views: " + e.getMessage());
        }
    }

    private void save() {
        File f = new File(STORAGE_PATH);
        f.getParentFile().mkdirs();
        try (ObjectOutputStream oos = new ObjectOutputStream(new FileOutputStream(f))) {
            oos.writeObject(views);
        } catch (IOException e) {
            e.printStackTrace();
        }
    }
}
