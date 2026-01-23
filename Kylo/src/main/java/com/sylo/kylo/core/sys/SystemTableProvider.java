package com.sylo.kylo.core.sys;

import com.sylo.kylo.core.catalog.Catalog;
import com.sylo.kylo.core.catalog.Column;
import com.sylo.kylo.core.catalog.Schema;
import com.sylo.kylo.core.structure.PlanNode;
import com.sylo.kylo.core.structure.RowHeader;
import com.sylo.kylo.core.structure.Tuple;
import java.util.ArrayList;
import java.util.Iterator;
import java.util.List;

public class SystemTableProvider {

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
                // TABLES: TABLE_CATALOG, TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE, ENGINE, VERSION,
                // ROW_FORMAT, TABLE_ROWS, ...
                for (String fullName : catalog.getAllTableNames()) {
                    String[] parts = fullName.contains(":") ? fullName.split(":")
                            : new String[] { "default", fullName };
                    String db = parts.length > 1 ? parts[0] : "default";
                    String tbl = parts.length > 1 ? parts[1] : parts[0];

                    rows.add(createTuple(
                            "def", // TABLE_CATALOG
                            db, // TABLE_SCHEMA
                            tbl, // TABLE_NAME
                            "BASE TABLE", // TABLE_TYPE
                            "KyloDB", // ENGINE
                            10, // VERSION
                            "Fixed", // ROW_FORMAT
                            0L, // TABLE_ROWS (Estimate)
                            0L, // AVG_ROW_LENGTH
                            0L, // DATA_LENGTH
                            0L, // MAX_DATA_LENGTH
                            0L, // INDEX_LENGTH
                            0L, // DATA_FREE
                            null, // AUTO_INCREMENT
                            null, // CREATE_TIME
                            null, // UPDATE_TIME
                            null, // CHECK_TIME
                            "utf8mb4_0900_ai_ci", // TABLE_COLLATION
                            null, // CHECKSUM
                            "", // CREATE_OPTIONS
                            "" // TABLE_COMMENT
                    ));
                }
            } else if (tableName.equalsIgnoreCase("COLUMNS")) {
                // COLUMNS: TABLE_CATALOG, TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME,
                // ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE,
                // CHARACTER_MAXIMUM_LENGTH, CHARACTER_OCTET_LENGTH, NUMERIC_PRECISION,
                // NUMERIC_SCALE, DATETIME_PRECISION, CHARACTER_SET_NAME, COLLATION_NAME,
                // COLUMN_TYPE, COLUMN_KEY, EXTRA, PRIVILEGES, COLUMN_COMMENT,
                // GENERATION_EXPRESSION
                for (String fullName : catalog.getAllTableNames()) {
                    String[] parts = fullName.contains(":") ? fullName.split(":")
                            : new String[] { "default", fullName };
                    String db = parts.length > 1 ? parts[0] : "default";
                    String tbl = parts.length > 1 ? parts[1] : parts[0];

                    Schema s = catalog.getTableSchema(fullName);
                    for (int i = 0; i < s.getColumnCount(); i++) {
                        Column c = s.getColumn(i);
                        String kyloType = c.getType().toString();
                        String mysqlType = mapToMysqlType(kyloType);
                        String fullType = mapToFullType(kyloType);

                        Long charMaxLen = getLength(kyloType);
                        Long charOctLen = (charMaxLen != null) ? charMaxLen * 4 : null;

                        rows.add(createTuple(
                                "def", // TABLE_CATALOG
                                db, // TABLE_SCHEMA
                                tbl, // TABLE_NAME
                                c.getName(), // COLUMN_NAME
                                (long) (i + 1), // ORDINAL_POSITION
                                null, // COLUMN_DEFAULT
                                "YES", // IS_NULLABLE
                                mysqlType, // DATA_TYPE
                                charMaxLen, // CHARACTER_MAXIMUM_LENGTH
                                charOctLen, // CHARACTER_OCTET_LENGTH
                                null, // NUMERIC_PRECISION
                                null, // NUMERIC_SCALE
                                null, // DATETIME_PRECISION
                                "utf8mb4", // CHARACTER_SET_NAME
                                "utf8mb4_0900_ai_ci", // COLLATION_NAME
                                fullType, // COLUMN_TYPE
                                "", // COLUMN_KEY
                                "", // EXTRA
                                "select,insert,update,references", // PRIVILEGES
                                "", // COLUMN_COMMENT
                                "" // GENERATION_EXPRESSION
                        ));
                    }
                }
            } else if (tableName.equalsIgnoreCase("SCHEMATA")) {
                // CATALOG_NAME, SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME,
                // DEFAULT_COLLATION_NAME, SQL_PATH, DEFAULT_ENCRYPTION
                for (String db : catalog.getDatabases()) {
                    rows.add(createTuple(
                            "def",
                            db,
                            "utf8mb4",
                            "utf8mb4_0900_ai_ci",
                            null,
                            "NO"));
                }
            } else if (tableName.equalsIgnoreCase("KEY_COLUMN_USAGE")) {
                // Empty for now to satisfy DBeaver
            }

            iterator = rows.iterator();
        }

        private String mapToMysqlType(String kyloType) {
            if (kyloType.contains("KyloVarchar"))
                return "varchar";
            if (kyloType.contains("KyloUuid"))
                return "varchar";
            if (kyloType.contains("KyloBoolean"))
                return "tinyint";
            if (kyloType.contains("KyloInt"))
                return "int";
            if (kyloType.contains("KyloBigInt"))
                return "bigint";
            return "text";
        }

        private String mapToFullType(String kyloType) {
            if (kyloType.contains("KyloVarchar")) {
                // Extract length if possible, or default
                return "varchar(255)";
            }
            if (kyloType.contains("KyloUuid"))
                return "varchar(36)";
            if (kyloType.contains("KyloBoolean"))
                return "tinyint(1)";
            if (kyloType.contains("KyloInt"))
                return "int";
            if (kyloType.contains("KyloBigInt"))
                return "bigint";
            return "text";
        }

        private Long getLength(String kyloType) {
            if (kyloType.contains("KyloVarchar"))
                return 255L; // Placeholder
            if (kyloType.contains("KyloUuid"))
                return 36L;
            return null;
        }

        private Tuple createTuple(Object... values) {
            return new Tuple(new RowHeader(), values);
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

    public static Schema getSchema(String tableName) {
        List<Column> cols = new ArrayList<>();
        if (tableName.equalsIgnoreCase("TABLES")) {
            cols.add(new Column("TABLE_CATALOG", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("TABLE_SCHEMA", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("TABLE_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("TABLE_TYPE", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("ENGINE", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("VERSION", new com.sylo.kylo.core.structure.KyloInt(), false));
            cols.add(new Column("ROW_FORMAT", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("TABLE_ROWS", new com.sylo.kylo.core.structure.KyloBigInt(), false));
            cols.add(new Column("AVG_ROW_LENGTH", new com.sylo.kylo.core.structure.KyloBigInt(), false));
            cols.add(new Column("DATA_LENGTH", new com.sylo.kylo.core.structure.KyloBigInt(), false));
            cols.add(new Column("MAX_DATA_LENGTH", new com.sylo.kylo.core.structure.KyloBigInt(), false));
            cols.add(new Column("INDEX_LENGTH", new com.sylo.kylo.core.structure.KyloBigInt(), false));
            cols.add(new Column("DATA_FREE", new com.sylo.kylo.core.structure.KyloBigInt(), false));
            cols.add(new Column("AUTO_INCREMENT", new com.sylo.kylo.core.structure.KyloBigInt(), true));
            cols.add(new Column("CREATE_TIME", new com.sylo.kylo.core.structure.KyloVarchar(255), true));
            cols.add(new Column("UPDATE_TIME", new com.sylo.kylo.core.structure.KyloVarchar(255), true));
            cols.add(new Column("CHECK_TIME", new com.sylo.kylo.core.structure.KyloVarchar(255), true));
            cols.add(new Column("TABLE_COLLATION", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("CHECKSUM", new com.sylo.kylo.core.structure.KyloBigInt(), true));
            cols.add(new Column("CREATE_OPTIONS", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("TABLE_COMMENT", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
        } else if (tableName.equalsIgnoreCase("COLUMNS")) {
            cols.add(new Column("TABLE_CATALOG", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("TABLE_SCHEMA", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("TABLE_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("COLUMN_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("ORDINAL_POSITION", new com.sylo.kylo.core.structure.KyloBigInt(), false));
            cols.add(new Column("COLUMN_DEFAULT", new com.sylo.kylo.core.structure.KyloVarchar(255), true));
            cols.add(new Column("IS_NULLABLE", new com.sylo.kylo.core.structure.KyloVarchar(3), false));
            cols.add(new Column("DATA_TYPE", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("CHARACTER_MAXIMUM_LENGTH", new com.sylo.kylo.core.structure.KyloBigInt(), true));
            cols.add(new Column("CHARACTER_OCTET_LENGTH", new com.sylo.kylo.core.structure.KyloBigInt(), true));
            cols.add(new Column("NUMERIC_PRECISION", new com.sylo.kylo.core.structure.KyloBigInt(), true));
            cols.add(new Column("NUMERIC_SCALE", new com.sylo.kylo.core.structure.KyloBigInt(), true));
            cols.add(new Column("DATETIME_PRECISION", new com.sylo.kylo.core.structure.KyloBigInt(), true));
            cols.add(new Column("CHARACTER_SET_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), true));
            cols.add(new Column("COLLATION_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), true));
            cols.add(new Column("COLUMN_TYPE", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("COLUMN_KEY", new com.sylo.kylo.core.structure.KyloVarchar(3), false));
            cols.add(new Column("EXTRA", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("PRIVILEGES", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("COLUMN_COMMENT", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("GENERATION_EXPRESSION", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
        } else if (tableName.equalsIgnoreCase("SCHEMATA")) {
            cols.add(new Column("CATALOG_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("SCHEMA_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(
                    new Column("DEFAULT_CHARACTER_SET_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("DEFAULT_COLLATION_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("SQL_PATH", new com.sylo.kylo.core.structure.KyloVarchar(255), true));
            cols.add(new Column("DEFAULT_ENCRYPTION", new com.sylo.kylo.core.structure.KyloVarchar(3), false));
        } else {
            // Fallback for KEYWORDS etc
            cols.add(new Column("DUMMY", new com.sylo.kylo.core.structure.KyloVarchar(1), true));
        }
        return new Schema(cols);
    }
}
