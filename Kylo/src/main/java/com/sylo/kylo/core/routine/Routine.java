package com.sylo.kylo.core.routine;

import java.io.Serializable;
import java.time.LocalDateTime;
import java.util.List;

@SuppressWarnings("unused") // Future feature: Stored Procedures/Functions model
public class Routine implements Serializable {
    private static final long serialVersionUID = 1L;

    public enum RoutineType {
        PROCEDURE,
        FUNCTION
    }

    public enum Language {
        SQL,
        LUA,
        JS
    }

    private String name;
    private String db;
    private RoutineType type;
    private Language language;
    private String specificName;
    private String body;
    private String returns; // For functions
    private List<String> params; // Simple list of param definitions for now
    private boolean isDeterministic;
    private String comment;
    private String definer;
    private LocalDateTime created;
    private LocalDateTime modified;

    public Routine(String db, String name, RoutineType type, Language language, String body) {
        this.db = db;
        this.name = name;
        this.type = type;
        this.language = language;
        this.body = body;
        this.created = LocalDateTime.now();
        this.modified = LocalDateTime.now();
        this.definer = "root@localhost"; // Default
    }

    // Getters and Setters
    public String getName() {
        return name;
    }

    public String getDb() {
        return db;
    }

    public RoutineType getType() {
        return type;
    }

    public Language getLanguage() {
        return language;
    }

    public String getBody() {
        return body;
    }

    public String getReturns() {
        return returns;
    }

    public void setReturns(String returns) {
        this.returns = returns;
    }

    public List<String> getParams() {
        return params;
    }

    public void setParams(List<String> params) {
        this.params = params;
    }

    public boolean isDeterministic() {
        return isDeterministic;
    }

    public void setDeterministic(boolean deterministic) {
        isDeterministic = deterministic;
    }

    public String getDefiner() {
        return definer;
    }

    public void setDefiner(String definer) {
        this.definer = definer;
    }

    public LocalDateTime getCreated() {
        return created;
    }

    public LocalDateTime getModified() {
        return modified;
    }

    @Override
    public String toString() {
        return type + " " + db + "." + name + " (" + language + ")";
    }
}
