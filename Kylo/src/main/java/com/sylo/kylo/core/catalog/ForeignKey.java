package com.sylo.kylo.core.catalog;

public class ForeignKey {
    public String constraintName;
    public String childTable;
    public String childColumn;
    public String parentTable;
    public String parentColumn;

    public ForeignKey(String constraintName, String childTable, String childColumn, String parentTable,
            String parentColumn) {
        this.constraintName = constraintName;
        this.childTable = childTable;
        this.childColumn = childColumn;
        this.parentTable = parentTable;
        this.parentColumn = parentColumn;
    }
}
