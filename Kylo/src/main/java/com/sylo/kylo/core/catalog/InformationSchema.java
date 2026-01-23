package com.sylo.kylo.core.catalog;

import com.sylo.kylo.core.structure.PlanNode;
import com.sylo.kylo.core.structure.RowHeader;
import com.sylo.kylo.core.structure.Tuple;
import java.util.ArrayList;
import java.util.Iterator;
import java.util.List;

public class InformationSchema {

    public static PlanNode getSystemTableScan(String tableName) {
        return new SystemTableNode(tableName);
    }

    private static class SystemTableNode extends PlanNode {
        private String tableName;
        private Iterator<Tuple> iterator;

        public SystemTableNode(String tableName) {
            this.tableName = tableName;
        }

        @Override
        public void open() {
            List<Tuple> rows = new ArrayList<>();
            Catalog catalog = Catalog.getInstance();

            if (tableName.equalsIgnoreCase("TABLES")) {
                // TABLES: TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE
                for (String t : catalog.getAllTableNames()) {
                    rows.add(createTuple("PUBLIC", t, "BASE TABLE"));
                }
            } else if (tableName.equalsIgnoreCase("COLUMNS")) {
                // COLUMNS: TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, DATA_TYPE,
                // IS_NULLABLE
                for (String t : catalog.getAllTableNames()) {
                    Schema s = catalog.getTableSchema(t);
                    for (int i = 0; i < s.getColumnCount(); i++) {
                        Column c = s.getColumn(i);
                        rows.add(createTuple(
                                "PUBLIC",
                                t,
                                c.getName(),
                                (long) (i + 1), // Ordinal
                                c.getType().toString(),
                                "YES" // Is Nullable
                        ));
                    }
                }
            } else if (tableName.equalsIgnoreCase("SCHEMATA")) {
                rows.add(createTuple("def", "PUBLIC", "UTF8", "default"));
            } else if (tableName.equalsIgnoreCase("KEY_COLUMN_USAGE")) {
                // Mock implementation for DBeaver compatibility
                // CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME, TABLE_CATALOG,
                // TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION
                // Empty for now or simple PKs
            }

            iterator = rows.iterator();
        }

        private Tuple createTuple(Object... values) {
            return new Tuple(new RowHeader(), values); // Use default header
        }

        @Override
        public Tuple next() {
            return (iterator != null && iterator.hasNext()) ? iterator.next() : null;
        }

        @Override
        public void close() {
            iterator = null;
        }
    }
}
