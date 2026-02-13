package com.sylo.kylo.core.event;

import java.io.Serializable;
import java.time.LocalDateTime;

@SuppressWarnings("unused") // Future feature: MySQL Events
public class Event implements Serializable {
    private static final long serialVersionUID = 1L;

    public enum Status {
        ENABLED,
        DISABLED,
        SLAVESIDE_DISABLED
    }

    private String name;
    private String schema;
    private String body;
    private String definer;
    private LocalDateTime executeAt;
    private long intervalValue;
    private String intervalField; // SECOND, MINUTE, etc.
    private Status status;
    private LocalDateTime created;
    private LocalDateTime lastExecuted;

    public Event(String schema, String name, String body) {
        this.schema = schema;
        this.name = name;
        this.body = body;
        this.created = LocalDateTime.now();
        this.status = Status.ENABLED;
        this.definer = "root@localhost";
    }

    public String getName() {
        return name;
    }

    public String getSchema() {
        return schema;
    }

    public String getBody() {
        return body;
    }

    public Status getStatus() {
        return status;
    }

    public void setStatus(Status status) {
        this.status = status;
    }

    public LocalDateTime getExecuteAt() {
        return executeAt;
    }

    public void setExecuteAt(LocalDateTime executeAt) {
        this.executeAt = executeAt;
    }

    public long getIntervalValue() {
        return intervalValue;
    }

    public void setIntervalValue(long intervalValue) {
        this.intervalValue = intervalValue;
    }

    public String getIntervalField() {
        return intervalField;
    }

    public void setIntervalField(String intervalField) {
        this.intervalField = intervalField;
    }

    public LocalDateTime getLastExecuted() {
        return lastExecuted;
    }

    public void setLastExecuted(LocalDateTime lastExecuted) {
        this.lastExecuted = lastExecuted;
    }

    @Override
    public String toString() {
        return "EVENT " + schema + "." + name + " [" + status + "]";
    }
}
