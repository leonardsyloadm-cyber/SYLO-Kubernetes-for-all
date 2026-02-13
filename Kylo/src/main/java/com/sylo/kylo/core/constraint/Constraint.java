package com.sylo.kylo.core.constraint;

import java.io.Serializable;
import java.util.List;

public class Constraint implements Serializable {
    private static final long serialVersionUID = 1L;

    public enum Type {
        PRIMARY_KEY,
        UNIQUE,
        FOREIGN_KEY
    }

    private String name;
    private Type type;
    private String table;
    private List<String> columns;

    // Foreign Key specifics
    private String refTable;
    private List<String> refColumns;
    private String onDelete; // CASCADE, RESTRICT, SET NULL, NO ACTION
    private String onUpdate; // CASCADE, RESTRICT, SET NULL, NO ACTION

    public Constraint(String name, Type type, String table, List<String> columns) {
        this.name = name;
        this.type = type;
        this.table = table;
        this.columns = columns;
    }

    // Constructor for FK (backward compatible)
    public Constraint(String name, String table, List<String> columns, String refTable, List<String> refColumns) {
        this(name, table, columns, refTable, refColumns, "NO ACTION", "NO ACTION");
    }

    // Constructor for FK with CASCADE rules
    public Constraint(String name, String table, List<String> columns, String refTable, List<String> refColumns,
            String onDelete, String onUpdate) {
        this.name = name;
        this.type = Type.FOREIGN_KEY;
        this.table = table;
        this.columns = columns;
        this.refTable = refTable;
        this.refColumns = refColumns;
        this.onDelete = onDelete != null ? onDelete : "NO ACTION";
        this.onUpdate = onUpdate != null ? onUpdate : "NO ACTION";
    }

    public String getName() {
        return name;
    }

    public Type getType() {
        return type;
    }

    public String getTable() {
        return table;
    }

    public List<String> getColumns() {
        return columns;
    }

    public String getRefTable() {
        return refTable;
    }

    public List<String> getRefColumns() {
        return refColumns;
    }

    public String getOnDelete() {
        return onDelete;
    }

    public String getOnUpdate() {
        return onUpdate;
    }

    @Override
    public String toString() {
        if (type == Type.FOREIGN_KEY) {
            return String.format("CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s(%s)",
                    name, String.join(",", columns), refTable, String.join(",", refColumns));
        }
        return String.format("CONSTRAINT %s %s (%s)", name, type, String.join(",", columns));
    }
}
