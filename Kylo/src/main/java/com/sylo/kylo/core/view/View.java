package com.sylo.kylo.core.view;

import java.io.Serializable;
import java.time.LocalDateTime;

@SuppressWarnings("unused") // Future feature: MySQL Views
public class View implements Serializable {
    private static final long serialVersionUID = 1L;

    private String name;
    private String database;
    private String viewDefinition; // SELECT ...
    private boolean isUpdatable;
    private String definer;
    private LocalDateTime created;

    public View(String database, String name, String viewDefinition) {
        this.database = database;
        this.name = name;
        this.viewDefinition = viewDefinition;
        this.created = LocalDateTime.now();
        this.definer = "root@localhost";
        this.isUpdatable = false; // Default
    }

    // Getters
    public String getName() {
        return name;
    }

    public String getDatabase() {
        return database;
    }

    public String getViewDefinition() {
        return viewDefinition;
    }

    public boolean isUpdatable() {
        return isUpdatable;
    }

    public String getDefiner() {
        return definer;
    }

    public LocalDateTime getCreated() {
        return created;
    }

    @Override
    public String toString() {
        return "VIEW " + database + "." + name;
    }
}
