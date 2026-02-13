package com.sylo.kylo.core.trigger;

import java.io.*;
import java.util.*;
import java.util.concurrent.ConcurrentHashMap;
import java.util.stream.Collectors;

public class TriggerManager {
    private static TriggerManager instance;
    private Map<String, Trigger> triggers; // Key: db.triggerName
    private static final String STORAGE_PATH = "kylo_system/settings/triggers.dat";

    private TriggerManager() {
        triggers = new ConcurrentHashMap<>();
        load();
    }

    public static synchronized TriggerManager getInstance() {
        if (instance == null) {
            instance = new TriggerManager();
        }
        return instance;
    }

    public void addTrigger(Trigger t) {
        triggers.put(generateKey(t.getTriggerSchema(), t.getName()), t);
        save();
        System.out.println("Trigger added: " + t);
    }

    public Trigger getTrigger(String schema, String name) {
        return triggers.get(generateKey(schema, name));
    }

    public void dropTrigger(String schema, String name) {
        triggers.remove(generateKey(schema, name));
        save();
    }

    public List<Trigger> getTriggersForTable(String schema, String table) {
        return triggers.values().stream()
                .filter(t -> t.getTriggerSchema().equalsIgnoreCase(schema) && t.getEventTable().equalsIgnoreCase(table))
                .collect(Collectors.toList());
    }

    public Map<String, Trigger> getAllTriggers() {
        return triggers;
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
            triggers = (Map<String, Trigger>) ois.readObject();
        } catch (Exception e) {
            System.err.println("Error loading triggers: " + e.getMessage());
        }
    }

    private void save() {
        File f = new File(STORAGE_PATH);
        f.getParentFile().mkdirs();
        try (ObjectOutputStream oos = new ObjectOutputStream(new FileOutputStream(f))) {
            oos.writeObject(triggers);
        } catch (IOException e) {
            e.printStackTrace();
        }
    }
}
