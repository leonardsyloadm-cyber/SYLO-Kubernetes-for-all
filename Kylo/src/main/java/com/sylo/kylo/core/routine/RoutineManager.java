package com.sylo.kylo.core.routine;

import java.io.*;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

public class RoutineManager {
    private static RoutineManager instance;
    private Map<String, Routine> routines; // Key: db.name
    private static final String STORAGE_PATH = "kylo_system/settings/routines.dat";

    private RoutineManager() {
        routines = new ConcurrentHashMap<>();
        load();
    }

    public static synchronized RoutineManager getInstance() {
        if (instance == null) {
            instance = new RoutineManager();
        }
        return instance;
    }

    public void addRoutine(Routine r) {
        routines.put(generateKey(r.getDb(), r.getName()), r);
        save();
        System.out.println("Routine added: " + r);
    }

    public Routine getRoutine(String db, String name) {
        return routines.get(generateKey(db, name));
    }

    public void dropRoutine(String db, String name) {
        routines.remove(generateKey(db, name));
        save();
    }

    public Map<String, Routine> getAllRoutines() {
        return routines;
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
            routines = (Map<String, Routine>) ois.readObject();
        } catch (Exception e) {
            System.err.println("Error loading routines: " + e.getMessage());
        }
    }

    private void save() {
        File f = new File(STORAGE_PATH);
        f.getParentFile().mkdirs();
        try (ObjectOutputStream oos = new ObjectOutputStream(new FileOutputStream(f))) {
            oos.writeObject(routines);
        } catch (IOException e) {
            e.printStackTrace();
        }
    }
}
