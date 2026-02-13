package com.sylo.kylo.core.trigger;

import java.io.Serializable;
import java.time.LocalDateTime;

public class Trigger implements Serializable {
    private static final long serialVersionUID = 1L;

    public enum Timing {
        BEFORE,
        AFTER
    }

    public enum Event {
        INSERT,
        UPDATE,
        DELETE
    }

    private String name;
    private String triggerSchema;
    private String eventTable;
    private Timing timing;
    private Event event;
    private String statement; // The body
    private String definer;
    private LocalDateTime created;

    public Trigger(String schema, String name, String eventTable, Timing timing, Event event, String statement) {
        this.triggerSchema = schema;
        this.name = name;
        this.eventTable = eventTable;
        this.timing = timing;
        this.event = event;
        this.statement = statement;
        this.created = LocalDateTime.now();
        this.definer = "root@localhost";
    }

    public String getName() {
        return name;
    }

    public String getTriggerSchema() {
        return triggerSchema;
    }

    public String getEventTable() {
        return eventTable;
    }

    public Timing getTiming() {
        return timing;
    }

    public Event getEvent() {
        return event;
    }

    public String getStatement() {
        return statement;
    }

    public String getDefiner() {
        return definer;
    }

    public LocalDateTime getCreated() {
        return created;
    }

    @Override
    public String toString() {
        return "TRIGGER " + triggerSchema + "." + name + " " + timing + " " + event + " ON " + eventTable;
    }
}
