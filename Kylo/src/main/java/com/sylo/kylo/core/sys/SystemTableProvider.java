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
import java.util.Map;

public class SystemTableProvider {

    // Static helper methods for schema building (reduce duplication)
    private static void addCatalogSchemaTableColumns(List<Column> cols) {
        cols.add(new Column("TABLE_CATALOG", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
        cols.add(new Column("TABLE_SCHEMA", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
        cols.add(new Column("TABLE_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
    }

    public static PlanNode getSystemTableScan(String tableName) {
        return new SystemTableNode(tableName);
    }

    @SuppressWarnings("unused") // Private helper methods used by open() and getSchema()
    private static class SystemTableNode extends PlanNode {
        private String tableName;
        private Iterator<Tuple> iterator;

        public SystemTableNode(String tableName) {
            this.tableName = tableName;
        }

        // Helper methods to reduce duplication
        private void addSchemaTableColumns(List<Column> cols) {
            cols.add(new Column("TABLE_SCHEMA", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("TABLE_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
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
            } else if (tableName.equalsIgnoreCase("VIEWS")) {
                Map<String, String> views = com.sylo.kylo.core.sql.ViewManager.getInstance().getAllViews();
                for (Map.Entry<String, String> entry : views.entrySet()) {
                    String[] parts = entry.getKey().contains(":") ? entry.getKey().split(":")
                            : new String[] { "default", entry.getKey() };
                    rows.add(createTuple(
                            "def", parts.length > 1 ? parts[0] : "default", parts.length > 1 ? parts[1] : parts[0],
                            entry.getValue(), "NONE", "NO", "def", "utf8mb4", "utf8mb4_0900_ai_ci"));
                }
            } else if (tableName.equalsIgnoreCase("TABLE_CONSTRAINTS")) {
                // TABLE_CONSTRAINTS: CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME,
                // TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_TYPE, ENFORCED
                for (String t : catalog.getAllTableNames()) {
                    String[] parts = t.contains(":") ? t.split(":") : new String[] { "default", t };
                    String db = parts.length > 1 ? parts[0] : "default";
                    String tbl = parts.length > 1 ? parts[1] : parts[0];

                    List<com.sylo.kylo.core.constraint.Constraint> constraints = catalog.getConstraintManager()
                            .getConstraints(t);
                    for (com.sylo.kylo.core.constraint.Constraint c : constraints) {
                        String type = "UNKNOWN";
                        if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.PRIMARY_KEY)
                            type = "PRIMARY KEY";
                        else if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.UNIQUE)
                            type = "UNIQUE";
                        else if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.FOREIGN_KEY)
                            type = "FOREIGN KEY";

                        rows.add(createTuple("def", db, c.getName(), db, tbl, type, "YES"));
                    }

                    // Fake PK if none exists? DBeaver likes PKs.
                    // Let's rely on real metadata now.
                }
            } else if (tableName.equalsIgnoreCase("KEY_COLUMN_USAGE")) {
                // CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME, TABLE_CATALOG,
                // TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION,
                // POSITION_IN_UNIQUE_CONSTRAINT, REFERENCED_TABLE_SCHEMA,
                // REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                for (String t : catalog.getAllTableNames()) {
                    String[] parts = t.contains(":") ? t.split(":") : new String[] { "default", t };
                    String db = parts.length > 1 ? parts[0] : "default";
                    String tbl = parts.length > 1 ? parts[1] : parts[0];

                    List<com.sylo.kylo.core.constraint.Constraint> constraints = catalog.getConstraintManager()
                            .getConstraints(t);
                    for (com.sylo.kylo.core.constraint.Constraint c : constraints) {
                        int ord = 1;
                        // For each column in constraint
                        for (int i = 0; i < c.getColumns().size(); i++) {
                            String col = c.getColumns().get(i);
                            String refDb = null;
                            String refTbl = null;
                            String refCol = null;

                            if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.FOREIGN_KEY) {
                                String fullRef = c.getRefTable();
                                String[] rParts = fullRef.contains(":") ? fullRef.split(":")
                                        : new String[] { "default", fullRef };
                                refDb = rParts.length > 1 ? rParts[0] : "default";
                                refTbl = rParts.length > 1 ? rParts[1] : rParts[0];
                                // Safe access
                                if (c.getRefColumns() != null && i < c.getRefColumns().size()) {
                                    refCol = c.getRefColumns().get(i);
                                }
                            }

                            // LOGGING (DEBUG)
                            System.out.println("DEBUG KCU: " + c.getName() + " Col: " + col + " Ref: " + refTbl);

                            rows.add(createTuple(
                                    "def", db, c.getName(), // Constraint Schema/Name
                                    "def", db, tbl, // Table
                                    col, // Column
                                    (long) ord, // Ordinal
                                    (long) ord, // Pos in Unique (Mocking as same ord to prevent NPE)
                                    refDb, refTbl, refCol // Refs
                            ));
                            ord++;
                        }
                    }
                }
            } else if (tableName.equalsIgnoreCase("REFERENTIAL_CONSTRAINTS")) {
                // ... existing code ...
                for (String t : catalog.getAllTableNames()) {
                    String[] parts = t.contains(":") ? t.split(":") : new String[] { "default", t };
                    String db = parts.length > 1 ? parts[0] : "default";
                    String tbl = parts.length > 1 ? parts[1] : parts[0];

                    List<com.sylo.kylo.core.constraint.Constraint> constraints = catalog.getConstraintManager()
                            .getConstraints(t);
                    for (com.sylo.kylo.core.constraint.Constraint c : constraints) {
                        if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.FOREIGN_KEY) {
                            String fullRef = c.getRefTable();
                            String[] rParts = fullRef.contains(":") ? fullRef.split(":")
                                    : new String[] { "default", fullRef };
                            String refDb = rParts.length > 1 ? rParts[0] : "default";
                            String refTbl = rParts.length > 1 ? rParts[1] : rParts[0];

                            // Safe Strings
                            String upRule = c.getOnUpdate() != null ? c.getOnUpdate() : "NO ACTION";
                            String delRule = c.getOnDelete() != null ? c.getOnDelete() : "NO ACTION";

                            rows.add(createTuple(
                                    "def", db, c.getName(), // Constraint
                                    "def", db, "PRIMARY", // Unique Constraint (Mocking PRIMARY)
                                    "NONE", // MATCH_OPTION
                                    upRule, // UPDATE_RULE
                                    delRule, // DELETE_RULE
                                    tbl, // TABLE_NAME
                                    refTbl // REFERENCED_TABLE_NAME
                            ));
                        }
                    }
                }
            } else if (tableName.equalsIgnoreCase("STATISTICS")) {
                // STATISTICS: TABLE_CATALOG, TABLE_SCHEMA, TABLE_NAME, NON_UNIQUE,
                // INDEX_SCHEMA, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, COLLATION,
                // CARDINALITY, SUB_PART, PACKED, NULLABLE, INDEX_TYPE, COMMENT,
                // INDEX_COMMENT, IS_VISIBLE, EXPRESSION
                for (String t : catalog.getAllTableNames()) {
                    String[] parts = t.contains(":") ? t.split(":") : new String[] { "default", t };
                    String db = parts.length > 1 ? parts[0] : "default";
                    String tbl = parts.length > 1 ? parts[1] : parts[0];

                    List<com.sylo.kylo.core.constraint.Constraint> constraints = catalog.getConstraintManager()
                            .getConstraints(t);
                    for (com.sylo.kylo.core.constraint.Constraint c : constraints) {
                        // Only PK, UNIQUE, INDEX are indexes (FK is not an index itself in MySQL I_S,
                        // though it requires one)
                        // Kylo handles FK by creating a backing index, but we should list that backing
                        // index if it exists?
                        // For now, list PK and UNIQUE as indexes.
                        if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.PRIMARY_KEY
                                || c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.UNIQUE) {

                            long nonUnique = (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.UNIQUE
                                    || c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.PRIMARY_KEY) ? 0L
                                            : 1L;
                            String indexName = c.getName(); // Use simple name!
                            if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.PRIMARY_KEY) {
                                indexName = "PRIMARY";
                            }

                            int seq = 1;
                            for (String col : c.getColumns()) {
                                rows.add(createTuple(
                                        "def", db, tbl, // Table
                                        nonUnique, // NON_UNIQUE
                                        db, indexName, // Index Schema/Name
                                        (long) seq, // SEQ_IN_INDEX
                                        col, // COLUMN_NAME
                                        "A", // COLLATION
                                        0L, // CARDINALITY
                                        null, // SUB_PART
                                        null, // PACKED
                                        "", // NULLABLE
                                        "BTREE", // INDEX_TYPE
                                        "", // COMMENT
                                        "", // INDEX_COMMENT
                                        "YES", // IS_VISIBLE
                                        null // EXPRESSION
                                ));
                                seq++;
                            }
                        }
                    }
                }
            }

            iterator = rows.iterator();
        }

        // ... map methods ...

        public static Schema getSchema(String tableName) {
            // ... existing ...
            // Need to scroll down to Schema definitions
            List<Column> cols = new ArrayList<>();
            if (tableName.equalsIgnoreCase("TABLES")) {
                // ...
            }
            // ...
            else if (tableName.equalsIgnoreCase("KEY_COLUMN_USAGE")) {
                // ... existing ...
                cols.add(new Column("CONSTRAINT_CATALOG", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
                cols.add(new Column("CONSTRAINT_SCHEMA", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
                cols.add(new Column("CONSTRAINT_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
                addCatalogSchemaTableColumns(cols);
                cols.add(new Column("COLUMN_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
                cols.add(new Column("ORDINAL_POSITION", new com.sylo.kylo.core.structure.KyloBigInt(), false));
                cols.add(new Column("POSITION_IN_UNIQUE_CONSTRAINT", new com.sylo.kylo.core.structure.KyloBigInt(),
                        true));
                cols.add(
                        new Column("REFERENCED_TABLE_SCHEMA", new com.sylo.kylo.core.structure.KyloVarchar(255), true));
                cols.add(new Column("REFERENCED_TABLE_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), true));
                cols.add(new Column("REFERENCED_COLUMN_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), true));
            } else if (tableName.equalsIgnoreCase("REFERENTIAL_CONSTRAINTS")) {
                cols.add(new Column("CONSTRAINT_CATALOG", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
                cols.add(new Column("CONSTRAINT_SCHEMA", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
                cols.add(new Column("CONSTRAINT_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
                cols.add(new Column("UNIQUE_CONSTRAINT_CATALOG", new com.sylo.kylo.core.structure.KyloVarchar(255),
                        true));
                cols.add(new Column("UNIQUE_CONSTRAINT_SCHEMA", new com.sylo.kylo.core.structure.KyloVarchar(255),
                        true));
                cols.add(new Column("UNIQUE_CONSTRAINT_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), true));
                cols.add(new Column("MATCH_OPTION", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
                cols.add(new Column("UPDATE_RULE", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
                cols.add(new Column("DELETE_RULE", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
                cols.add(new Column("TABLE_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
                cols.add(new Column("REFERENCED_TABLE_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            } else {
                cols.add(new Column("DUMMY", new com.sylo.kylo.core.structure.KyloVarchar(1), true));
            }
            return new Schema(cols);
        }

        private String mapToMysqlType(String kyloType) {
            String u = kyloType.toUpperCase();
            if (u.contains("VARCHAR") || u.contains("STRING"))
                return "varchar";
            if (u.contains("UUID"))
                return "varchar"; // MySQL uses varchar(36) for UUID usually or binary
            if (u.contains("BOOLEAN"))
                return "tinyint";
            if (u.contains("TINYINT"))
                return "tinyint";
            if (u.equals("INT") || u.contains("INTEGER") || u.contains("KYLOINT"))
                return "int";
            if (u.contains("BIGINT") || u.contains("LONG"))
                return "bigint";
            if (u.contains("DOUBLE") || u.contains("REAL"))
                return "double";
            if (u.contains("FLOAT"))
                return "float";
            if (u.contains("DECIMAL") || u.contains("NUMERIC"))
                return "decimal";
            if (u.contains("DATE") && !u.contains("TIME"))
                return "date"; // KyloDate
            if (u.contains("TIME") && !u.contains("STAMP"))
                return "time"; // KyloTime
            if (u.contains("TIMESTAMP") || u.contains("DATETIME"))
                return "timestamp";
            if (u.contains("YEAR"))
                return "year";
            if (u.contains("BLOB") || u.contains("BINARY"))
                return "blob";
            if (u.contains("JSON"))
                return "json";
            if (u.contains("ENUM"))
                return "enum";

            return "text";
        }

        private String mapToFullType(String kyloType) {
            String u = kyloType.toUpperCase();
            if (u.contains("VARCHAR") || u.contains("STRING"))
                return "varchar(255)";
            if (u.contains("UUID"))
                return "varchar(36)";
            if (u.contains("BOOLEAN"))
                return "tinyint(1)";
            if (u.contains("TINYINT"))
                return "tinyint(4)";
            if (u.equals("INT") || u.contains("INTEGER") || u.contains("KYLOINT"))
                return "int(11)";
            if (u.contains("BIGINT") || u.contains("LONG"))
                return "bigint(20)";
            if (u.contains("DOUBLE") || u.contains("REAL"))
                return "double";
            if (u.contains("FLOAT"))
                return "float";
            if (u.contains("DECIMAL") || u.contains("NUMERIC"))
                return "decimal(10,0)";
            if (u.contains("DATE") && !u.contains("TIME"))
                return "date";
            if (u.contains("TIME") && !u.contains("STAMP"))
                return "time";
            if (u.contains("TIMESTAMP") || u.contains("DATETIME"))
                return "timestamp";
            if (u.contains("YEAR"))
                return "year(4)";
            if (u.contains("BLOB") || u.contains("BINARY"))
                return "blob";
            if (u.contains("JSON"))
                return "json";
            if (u.contains("ENUM"))
                return "enum('Y','N')"; // Generic dummy for display if actual values unknown here

            return "text";
        }

        private Long getLength(String kyloType) {
            if (kyloType.contains("KyloVarchar"))
                return 255L;
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
            // ... existing ...
            addCatalogSchemaTableColumns(cols);
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
            // ... existing ... (lines 210-230)
            addCatalogSchemaTableColumns(cols);
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
        } else if (tableName.equalsIgnoreCase("STATISTICS")) {
            addCatalogSchemaTableColumns(cols);
            cols.add(new Column("NON_UNIQUE", new com.sylo.kylo.core.structure.KyloBigInt(), false));
            cols.add(new Column("INDEX_SCHEMA", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("INDEX_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("SEQ_IN_INDEX", new com.sylo.kylo.core.structure.KyloBigInt(), false));
            cols.add(new Column("COLUMN_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("COLLATION", new com.sylo.kylo.core.structure.KyloVarchar(1), true));
            cols.add(new Column("CARDINALITY", new com.sylo.kylo.core.structure.KyloBigInt(), true));
            cols.add(new Column("SUB_PART", new com.sylo.kylo.core.structure.KyloBigInt(), true));
            cols.add(new Column("PACKED", new com.sylo.kylo.core.structure.KyloVarchar(10), true));
            cols.add(new Column("NULLABLE", new com.sylo.kylo.core.structure.KyloVarchar(3), false));
            cols.add(new Column("INDEX_TYPE", new com.sylo.kylo.core.structure.KyloVarchar(16), false));
            cols.add(new Column("COMMENT", new com.sylo.kylo.core.structure.KyloVarchar(16), true));
            cols.add(new Column("INDEX_COMMENT", new com.sylo.kylo.core.structure.KyloVarchar(1024), false));
            cols.add(new Column("IS_VISIBLE", new com.sylo.kylo.core.structure.KyloVarchar(3), false));
            cols.add(new Column("EXPRESSION", new com.sylo.kylo.core.structure.KyloVarchar(1024), true));
        } else if (tableName.equalsIgnoreCase("VIEWS")) {
            addCatalogSchemaTableColumns(cols);
            cols.add(new Column("VIEW_DEFINITION", new com.sylo.kylo.core.structure.KyloVarchar(1024), false));
            cols.add(new Column("CHECK_OPTION", new com.sylo.kylo.core.structure.KyloVarchar(8), false));
            cols.add(new Column("IS_UPDATABLE", new com.sylo.kylo.core.structure.KyloVarchar(3), false));
            cols.add(new Column("DEFINER", new com.sylo.kylo.core.structure.KyloVarchar(77), false));
            cols.add(new Column("SECURITY_TYPE", new com.sylo.kylo.core.structure.KyloVarchar(7), false));
            cols.add(new Column("CHARACTER_SET_CLIENT", new com.sylo.kylo.core.structure.KyloVarchar(32), false));
            cols.add(new Column("COLLATION_CONNECTION", new com.sylo.kylo.core.structure.KyloVarchar(32), false));
        } else if (tableName.equalsIgnoreCase("TABLE_CONSTRAINTS")) {
            cols.add(new Column("CONSTRAINT_CATALOG", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("CONSTRAINT_SCHEMA", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("CONSTRAINT_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("TABLE_SCHEMA", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("TABLE_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("CONSTRAINT_TYPE", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("ENFORCED", new com.sylo.kylo.core.structure.KyloVarchar(3), false));
        } else if (tableName.equalsIgnoreCase("KEY_COLUMN_USAGE")) {
            // CONSTRAINT_CATALOG, CONSTRAINT_SCHEMA, CONSTRAINT_NAME, TABLE_CATALOG,
            // TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION,
            // POSITION_IN_UNIQUE_CONSTRAINT, REFERENCED_TABLE_SCHEMA,
            // REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            cols.add(new Column("CONSTRAINT_CATALOG", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("CONSTRAINT_SCHEMA", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("CONSTRAINT_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            addCatalogSchemaTableColumns(cols);
            cols.add(new Column("COLUMN_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            cols.add(new Column("ORDINAL_POSITION", new com.sylo.kylo.core.structure.KyloBigInt(), false));
            cols.add(new Column("POSITION_IN_UNIQUE_CONSTRAINT", new com.sylo.kylo.core.structure.KyloBigInt(), true));
            cols.add(new Column("REFERENCED_TABLE_SCHEMA", new com.sylo.kylo.core.structure.KyloVarchar(255), true));
            cols.add(new Column("REFERENCED_TABLE_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), true));
            cols.add(new Column("REFERENCED_COLUMN_NAME", new com.sylo.kylo.core.structure.KyloVarchar(255), true));
        } else {
            cols.add(new Column("DUMMY", new com.sylo.kylo.core.structure.KyloVarchar(1), true));
        }
        return new Schema(cols);
    }
}
