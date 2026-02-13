package com.sylo.kylo.core.event;

import java.io.*;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

@SuppressWarnings("unused") // Future feature: Event management
public class EventManager {
    private static EventManager instance;
    private Map<String, Event> events;
    private static final String STORAGE_PATH = "kylo_system/settings/events.dat";

    private EventManager() {
        events = new ConcurrentHashMap<>();
        load();
    }

    public static synchronized EventManager getInstance() {
        if (instance == null) {
            instance = new EventManager();
        }
        return instance;
    }

    public void addEvent(Event e) {
        events.put(generateKey(e.getSchema(), e.getName()), e);
        save();
        System.out.println("Event added: " + e);
    }

    public Event getEvent(String schema, String name) {
        return events.get(generateKey(schema, name));
    }

    public void dropEvent(String schema, String name) {
        events.remove(generateKey(schema, name));
        save();
    }

    public Map<String, Event> getAllEvents() {
        return events;
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
            events = (Map<String, Event>) ois.readObject();
        } catch (Exception e) {
            System.err.println("Error loading events: " + e.getMessage());
        }
    }

    private void save() {
        File f = new File(STORAGE_PATH);
        f.getParentFile().mkdirs();
        try (ObjectOutputStream oos = new ObjectOutputStream(new FileOutputStream(f))) {
            oos.writeObject(events);
        } catch (IOException e) {
            e.printStackTrace();
        }
    }
}
