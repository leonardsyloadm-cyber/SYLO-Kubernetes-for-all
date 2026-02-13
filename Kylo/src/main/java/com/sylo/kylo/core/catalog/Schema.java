package com.sylo.kylo.core.catalog;

import java.util.List;
import java.util.ArrayList;
import java.util.Collections;

public class Schema implements java.io.Serializable {
    private static final long serialVersionUID = 1L;
    private final List<Column> columns;

    public Schema(List<Column> columns) {
        this.columns = new ArrayList<>(columns);
    }

    public List<Column> getColumns() {
        return Collections.unmodifiableList(columns);
    }

    public int getColumnCount() {
        return columns.size();
    }

    public Column getColumn(int index) {
        return columns.get(index);
    }
}
