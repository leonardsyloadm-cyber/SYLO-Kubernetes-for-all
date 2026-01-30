package com.sylo.kylo.net.handler;

import com.sylo.kylo.core.execution.ExecutionEngine;
import com.sylo.kylo.net.protocol.MySQLPacket;
import com.sylo.kylo.net.protocol.PacketBuilder;
import com.sylo.kylo.core.catalog.Catalog;
import com.sylo.kylo.core.catalog.Schema;
import java.io.IOException;
import java.io.OutputStream;
import java.util.List;
import java.util.ArrayList;
import java.util.regex.*;
import java.util.Map;
import java.util.HashMap;
import com.sylo.kylo.core.security.SecurityUtils;

public class KyloBridge {
    private final ExecutionEngine engine;
    private final ResultSetWriter rsWriter;

    private final com.sylo.kylo.core.security.SecurityInterceptor interceptor;
    private final com.sylo.kylo.core.session.SessionContext session;

    public KyloBridge(ExecutionEngine engine) {
        this.engine = engine;
        this.rsWriter = new ResultSetWriter();
        this.interceptor = new com.sylo.kylo.core.security.SecurityInterceptor(engine);
        this.session = new com.sylo.kylo.core.session.SessionContext();
    }

    public void setCurrentDb(String db) {
        session.setCurrentDatabase(db);
    }

    public void executeQuery(String sql, OutputStream out, byte sequenceId) throws IOException {
        String cleanSql = sql.replaceAll("(?s)/\\*.*?\\*/", "").trim();

        // Specific MySQL System Table Mappings (Singular/Legacy -> Kylo Plural)
        // Specific MySQL System Table Mappings (Singular/Legacy -> Kylo Plural)
        cleanSql = cleanSql.replaceAll("(?i)mysql\\.user", "kylo_system.users");
        cleanSql = cleanSql.replaceAll("(?i)mysql\\.db", "kylo_system.db_privs");
        cleanSql = cleanSql.replaceAll("(?i)mysql\\.tables_priv", "kylo_system.table_privs");

        // Global compatibility fix: Redirect remaining 'mysql' schema to 'kylo_system'
        cleanSql = cleanSql.replaceAll("(?i)mysql\\.", "kylo_system.");

        String upper = cleanSql.toUpperCase();

        System.out.println("DEBUG: SQL Raw: " + sql);
        System.out.println("DEBUG: SQL Clean: [" + cleanSql + "]");

        try {
            if (upper.startsWith("SELECT DATABASE()")) {
                handleSelectDatabase(out, sequenceId);
            } else if (upper.startsWith("SELECT")) {
                if (upper.contains("@@")) {
                    handleSystemSelect(cleanSql, out, sequenceId);
                } else if (upper.contains("SUM((DATA_LENGTH+INDEX_LENGTH))")) {
                    // Hack for DBeaver DB Size check
                    handleSumDataLength(out, sequenceId);
                } else {
                    handleSelect(cleanSql, out, sequenceId);
                }
            } else if (upper.startsWith("INSERT")) {
                handleInsert(cleanSql, out, sequenceId);
            } else if (upper.startsWith("UPDATE")) {
                handleUpdate(cleanSql, out, sequenceId);
            } else if (upper.startsWith("CREATE USER")) {
                handleCreateUser(cleanSql, out, sequenceId);
            } else if (upper.startsWith("CREATE TRIGGER")) {
                handleCreateTrigger(cleanSql, out, sequenceId);
            } else if (upper.startsWith("GRANT")) {
                handleGrant(cleanSql, out, sequenceId);
            } else if (upper.startsWith("DROP USER")) {
                handleDropUser(cleanSql, out, sequenceId);
            } else if (upper.startsWith("REVOKE")) {
                handleRevoke(cleanSql, out, sequenceId);
            } else if (upper.startsWith("ALTER USER")) {
                handleAlterUser(cleanSql, out, sequenceId);
            } else if (upper.startsWith("CREATE INDEX")) {
                handleCreateIndex(cleanSql, out, sequenceId);
            } else if (upper.startsWith("CREATE")) {
                handleCreate(cleanSql, out, sequenceId);
            } else if (upper.startsWith("ALTER TABLE")) {
                handleAlterTable(cleanSql, out, sequenceId);
            } else if (upper.startsWith("USE")) {
                String[] p = cleanSql.split("\\s+");
                if (p.length > 1) {
                    String db = p[1].replace(";", "");
                    if (db.equalsIgnoreCase("mysql"))
                        db = "kylo_system";
                    session.setCurrentDatabase(db);
                }
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++sequenceId);
            } else if (upper.startsWith("SHOW DATABASES") || upper.startsWith("SHOW SCHEMAS")) {
                handleShowDatabases(out, sequenceId);
            } else if (upper.startsWith("SHOW CREATE TABLE")) {
                handleShowCreateTable(cleanSql, out, sequenceId);
            } else if (upper.startsWith("SHOW TABLES") || upper.startsWith("SHOW FULL TABLES")) {
                handleShowTables(cleanSql, out, sequenceId);
            } else if (upper.startsWith("SHOW TABLE STATUS")) {
                handleShowTableStatus(cleanSql, out, sequenceId);
            } else if (upper.startsWith("SHOW VARIABLES")) {
                handleShowVariables(cleanSql, out, sequenceId);
            } else if (upper.startsWith("SHOW ENGINES") || upper.startsWith("SHOW PLUGINS")
                    || upper.startsWith("SHOW CHARSET") || upper.startsWith("SHOW COLLATION")
                    || upper.startsWith("SHOW WARNINGS") || upper.startsWith("SHOW STATUS")) {
                handleMockEmptySet(out, sequenceId);
            } else if (upper.startsWith("SHOW PRIVILEGES")) {
                handleShowPrivileges(out, sequenceId);
            } else if (upper.startsWith("SHOW GRANTS")) {
                handleShowGrants(cleanSql, out, sequenceId);
            } else if (upper.startsWith("SHOW FULL PROCESSLIST") || upper.startsWith("SHOW PROCESSLIST")) {
                handleShowProcessList(out, sequenceId);
            } else if (upper.startsWith("SET")) { // Generic SET
                handleSet(cleanSql, out, sequenceId);
            } else if (upper.startsWith("COMMIT")) {
                com.sylo.kylo.core.transaction.TransactionManager.getInstance().commit(session.getSessionId(), engine);
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++sequenceId);
            } else if (upper.startsWith("ROLLBACK")) {
                com.sylo.kylo.core.transaction.TransactionManager.getInstance().rollback(session.getSessionId());
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++sequenceId);
            } else {
                MySQLPacket.writePacket(out,
                        PacketBuilder.buildError(1000, "KyloDB: Command not supported via MySQL Protocol yet: " + sql),
                        ++sequenceId);
            }
        } catch (Exception e) {
            e.printStackTrace();
            StackTraceElement elem = e.getStackTrace().length > 0 ? e.getStackTrace()[0]
                    : new StackTraceElement("Unk", "Unk", "Unk", 0);
            MySQLPacket.writePacket(out, PacketBuilder.buildError(500, "Ex: " + e.toString() + " @ " + elem.toString()),
                    ++sequenceId);
        }
    }

    private void handleSet(String sql, OutputStream out, byte seq) throws IOException {
        // Special cleanup for DBeaver's "SET AUTOCOMMIT = 0"
        String upper = sql.toUpperCase();
        if (upper.contains("AUTOCOMMIT")) {
            // Very naive parser
            boolean autoCommitState = true;
            if (upper.contains("=0") || upper.contains("= 0") || upper.contains("OFF")) {
                autoCommitState = false;
            }

            if (!autoCommitState) {
                // START TRANSACTION
                com.sylo.kylo.core.transaction.TransactionManager.getInstance()
                        .beginTransaction(session.getSessionId());
                System.out.println("🏁 START TRANSACTION (AutoCommit=0) for " + session.getSessionId());
            } else {
                // COMMIT AND END
                com.sylo.kylo.core.transaction.TransactionManager.getInstance().commit(session.getSessionId(), engine);
            }
        }

        Pattern p = Pattern.compile("SET\\s+(.*?)\\s*=\\s*(.*)", Pattern.CASE_INSENSITIVE);
        Matcher m = p.matcher(sql);
        if (m.find()) {
            String key = m.group(1).trim().replace("@@", "").replace("GLOBAL.", "").replace("SESSION.", "");
            String val = m.group(2).trim().replace("'", "");
            session.setVariable(key, val);
        }
        MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
    }

    private void handleSystemSelect(String sql, OutputStream out, byte seq) throws IOException {
        // Parse SELECT @@var1, @@var2
        String afterSelect = sql.substring(6).trim();
        String[] parts = afterSelect.split(",");

        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        List<Object> rowData = new ArrayList<>();

        for (String part : parts) {
            String clean = part.trim();
            String alias = clean;
            if (clean.toUpperCase().contains(" AS ")) {
                String[] aliasParts = clean.split("(?i) AS ");
                clean = aliasParts[0].trim();
                alias = aliasParts[1].trim();
            }

            String varName = clean.replace("@@", "").replaceAll("(?i)GLOBAL\\.", "").replaceAll("(?i)SESSION\\.", "");
            Object val = session.getVariable(varName); // Look up in context

            // If unknown, return empty/null or default
            if (val == null)
                val = "";

            cols.add(new com.sylo.kylo.core.catalog.Column(alias, new com.sylo.kylo.core.structure.KyloVarchar(255),
                    false));
            rowData.add(val.toString());
        }

        Schema s = new Schema(cols);
        List<Object[]> rows = new ArrayList<>();
        rows.add(rowData.toArray());

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleSelect(String sql, OutputStream out, byte seq) throws Exception {
        String[] parts = sql.split("\\s+");
        int fromIdx = -1;
        for (int i = 0; i < parts.length; i++)
            if (parts[i].equalsIgnoreCase("FROM"))
                fromIdx = i;

        if (fromIdx == -1 || fromIdx + 1 >= parts.length) {
            throw new Exception("Invalid SELECT syntax (Simple parser)");
        }

        String rawTable = parts[fromIdx + 1].replace(";", "").replace("`", "");
        String fullTable = "";
        Schema schema = null;

        // Handle db.table syntax (e.g. SYSTEM.users)
        if (rawTable.contains(".")) {
            String[] tParts = rawTable.split("\\.");
            String db = tParts[0];
            String tbl = tParts[1];
            if (db.equalsIgnoreCase("mysql"))
                db = "kylo_system"; // extra safety
            fullTable = db + ":" + tbl;
        } else {
            // Use current DB
            fullTable = session.getCurrentDatabase() + ":" + rawTable;
        }

        // Handle INFORMATION_SCHEMA virtual tables
        if (rawTable.toUpperCase().startsWith("INFORMATION_SCHEMA")
                || rawTable.toUpperCase().contains(":INFORMATION_SCHEMA")) {
            // Extract table name e.g. INFORMATION_SCHEMA.KEYWORDS
            String virtTable = rawTable;
            if (rawTable.contains(".")) {
                virtTable = rawTable.substring(rawTable.lastIndexOf(".") + 1);
            }
            fullTable = "INFORMATION_SCHEMA." + virtTable; // Special prefix for Engine

            // Get schema from Provider
            schema = com.sylo.kylo.core.sys.SystemTableProvider.getSchema(virtTable);
        } else {
            // Security Check
            // We use the fullTable "db:table" to check permission? Or just "table"?
            // Simplifying:
            String dbCheck = fullTable.split(":")[0];
            String tblCheck = fullTable.split(":")[1];

            interceptor.checkPermission(dbCheck, tblCheck, "SELECT");
            schema = Catalog.getInstance().getTableSchema(fullTable);
        }

        if (schema == null) {
            throw new Exception("Table '" + rawTable + "' not found.");
        }

        List<Object[]> rows = engine.scanTable(session.getSessionId(), fullTable);

        String upper = sql.toUpperCase();
        // Simple WHERE filter implementation (In-Memory)
        if (upper.contains("WHERE")) {
            try {
                // Extract WHERE clause
                // Very naive parser: assumes WHERE col = 'val'
                String whereClause = sql.substring(upper.indexOf("WHERE") + 5).trim();

                // Handle ORDER BY truncation if present
                if (whereClause.toUpperCase().contains("ORDER BY")) {
                    whereClause = whereClause.substring(0, whereClause.toUpperCase().indexOf("ORDER BY")).trim();
                }

                // Split by AND (Naive support for multiple conditions)
                String[] conditions = whereClause.toUpperCase().split("\\s+AND\\s+");

                for (String condRaw : conditions) {
                    if (!condRaw.contains("="))
                        continue;

                    // Recover original case for value parsing if possible, or just parse carefully
                    // The 'conditions' array is UPPERCASE from the split source?
                    // No, wait. split on UpperCase keys but keep original string?
                    // Let's do regex split on original string.
                }

                // Better approach: Regex split on original string
                String[] rawConditions = whereClause.split("(?i)\\s+AND\\s+");

                for (String cond : rawConditions) {
                    if (!cond.contains("="))
                        continue;
                    String[] condParts = cond.split("=");
                    String colRaw = condParts[0].trim();
                    String valRaw = condParts[1].trim();

                    // Clean value (remove junk like LIMIT, ORDER BY if it's the last one)
                    // (Only applies to the last condition really, but loop logic handles it if
                    // rigorous)
                    String[] separators = new String[] { " LIMIT ", " GROUP BY ", " ORDER BY " };
                    for (String sep : separators) {
                        if (valRaw.toUpperCase().contains(sep)) {
                            valRaw = valRaw.substring(0, valRaw.toUpperCase().indexOf(sep)).trim();
                        }
                    }

                    final String valClean = valRaw.replace("'", "").replace("\"", "");

                    // Handle table.col syntax
                    String colName = colRaw;
                    if (colName.contains(".")) {
                        colName = colName.substring(colName.lastIndexOf(".") + 1);
                    }
                    String targetCol = colName.trim().replace("`", "");

                    // Find col index
                    int colIdx = -1;
                    for (int i = 0; i < schema.getColumnCount(); i++) {
                        if (schema.getColumn(i).getName().equalsIgnoreCase(targetCol)) {
                            colIdx = i;
                            break;
                        }
                    }

                    if (colIdx != -1) {
                        final int idx = colIdx;
                        List<Object[]> nextRows = new ArrayList<>();
                        for (Object[] r : rows) {
                            if (r[idx] != null && r[idx].toString().equalsIgnoreCase(valClean)) {
                                nextRows.add(r);
                            }
                        }
                        rows = nextRows; // Chain filter
                    }
                }
            } catch (Exception e) {
                // Ignore filtering errors, return full set (graceful degradation)
                System.out.println("Filter Warning: " + e.getMessage());
            }
        }

        rsWriter.writeResultSet(out, rows, schema, seq);
    }

    private void handleUpdate(String sql, OutputStream out, byte seq) throws IOException {
        try {
            // Basic Parser: UPDATE Table SET col=val WHERE ...
            String pattern = "(?i)UPDATE\\s+['`]?(?:(\\w+)\\.)?(\\w+)['`]?\\s+SET\\s+(.*?)(\\s+WHERE\\s+.*)?$";
            Pattern p = Pattern.compile(pattern, Pattern.DOTALL);
            Matcher m = p.matcher(sql);

            if (m.find()) {
                String db = m.group(1);
                String table = m.group(2);
                String setClause = m.group(3);
                String whereClauseRaw = m.group(4);

                if (db == null)
                    db = session.getCurrentDatabase();
                if (db.equalsIgnoreCase("mysql"))
                    db = "kylo_system";

                String fullTable = db + ":" + table;
                Schema schema = Catalog.getInstance().getTableSchema(fullTable);
                if (schema == null)
                    throw new Exception("Table " + fullTable + " not found");

                // Parse assignments (col=val, col2=val2)
                String[] assigns = setClause.split(",");
                Map<Integer, Object> changes = new HashMap<>();

                for (String assign : assigns) {
                    String[] parts = assign.split("=");
                    if (parts.length == 2) {
                        String colName = parts[0].trim().replace("`", "");
                        String valRaw = parts[1].trim();

                        int colIdx = -1;
                        for (int i = 0; i < schema.getColumnCount(); i++) {
                            if (schema.getColumn(i).getName().equalsIgnoreCase(colName)) {
                                colIdx = i;
                                break;
                            }
                        }

                        if (colIdx != -1) {
                            changes.put(colIdx, parseValue(valRaw, schema.getColumn(colIdx).getType()));
                        }
                    }
                }

                // Parse WHERE (Naive equality check only)
                final int filterColIdx;
                final Object filterVal;

                if (whereClauseRaw != null) {
                    // Extract "WHERE col = val"
                    String whereClean = whereClauseRaw.trim().substring(5).trim(); // remove WHERE
                    if (whereClean.contains("=")) {
                        String[] cond = whereClean.split("=");
                        String cName = cond[0].trim().replace("`", "");
                        if (cName.contains("."))
                            cName = cName.substring(cName.lastIndexOf(".") + 1); // remove db/alias prefix
                        String vRaw = cond[1].trim();
                        // Remove limit/order by junk
                        String[] separators = new String[] { " AND ", " OR ", " LIMIT ", " GROUP BY ", " ORDER BY " };
                        for (String sep : separators) {
                            if (vRaw.toUpperCase().contains(sep)) {
                                vRaw = vRaw.substring(0, vRaw.toUpperCase().indexOf(sep)).trim();
                            }
                        }

                        int idx = -1;
                        for (int i = 0; i < schema.getColumnCount(); i++) {
                            if (schema.getColumn(i).getName().equalsIgnoreCase(cName)) {
                                idx = i;
                                break;
                            }
                        }
                        filterColIdx = idx;
                        filterVal = (idx != -1) ? parseValue(vRaw, schema.getColumn(idx).getType()) : null;
                    } else {
                        filterColIdx = -1;
                        filterVal = null;
                    }
                } else {
                    filterColIdx = -1;
                    filterVal = null; // No updates without WHERE? standard MySQL allows it.
                }

                java.util.function.Predicate<com.sylo.kylo.core.structure.Tuple> predicate = t -> {
                    if (whereClauseRaw == null)
                        return true; // Update ALL
                    if (filterColIdx == -1)
                        return false; // parse failed, safer to not update

                    Object val = t.getValue(filterColIdx);
                    // types might be mismatched (Long vs Integer), do string comparison or robust
                    // equals
                    if (val == null)
                        return filterVal == null;
                    return val.toString().equals(filterVal.toString());
                };

                engine.updateTuple(session.getSessionId(), fullTable, changes, predicate);
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(1, 0), ++seq); // Say 1 row affected
            } else {
                throw new Exception("UPDATE syntax not supported yet");
            }
        } catch (Exception e) {
            e.printStackTrace();
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "UPDATE Error: " + e.getMessage()), ++seq);
        }
    }

    private void handleShowTables(String sql, OutputStream out, byte seq) throws Exception {
        boolean full = sql.toUpperCase().contains("FULL");
        String targetDb = session.getCurrentDatabase();

        // Check for FROM or IN with support for quotes/backticks
        Pattern p = Pattern.compile("SHOW\\s+(?:FULL\\s+)?TABLES\\s+(?:FROM|IN)\\s+['`]?(\\w+)['`]?",
                Pattern.CASE_INSENSITIVE);
        Matcher m = p.matcher(sql);
        if (m.find()) {
            targetDb = m.group(1);
        }

        if (targetDb.equalsIgnoreCase("mysql"))
            targetDb = "kylo_system";

        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("Tables_in_" + targetDb,
                new com.sylo.kylo.core.structure.KyloVarchar(255), false));
        if (full) {
            cols.add(new com.sylo.kylo.core.catalog.Column("Table_type",
                    new com.sylo.kylo.core.structure.KyloVarchar(20), false));
        }

        Schema s = new Schema(cols);

        List<Object[]> rows = new ArrayList<>();
        var all = Catalog.getInstance().getTables();

        for (String k : all.keySet()) {
            // k format is "db:table" or "table" (legacy)
            String dbName = "default";
            String tblName = k;

            if (k.contains(":")) {
                String[] parts = k.split(":");
                dbName = parts[0];
                tblName = parts[1];
            }

            // Filter by targetDb
            if (dbName.equalsIgnoreCase(targetDb)) {
                if (full) {
                    rows.add(new Object[] { tblName, "BASE TABLE" });
                } else {
                    rows.add(new Object[] { tblName });
                }
            }
        }

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleShowCreateTable(String sql, OutputStream out, byte seq) throws IOException {
        // Parse tablename
        String table = "";
        Pattern p = Pattern.compile("SHOW\\s+CREATE\\s+TABLE\\s+(.*)", Pattern.CASE_INSENSITIVE);
        Matcher m = p.matcher(sql);
        if (m.find()) {
            table = m.group(1).trim().replace(";", "").replace("`", "");
            // Remove db prefix if present in SQL
            if (table.contains(".")) {
                table = table.substring(table.lastIndexOf(".") + 1);
            }
        }

        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("Table", new com.sylo.kylo.core.structure.KyloVarchar(64),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Create Table",
                new com.sylo.kylo.core.structure.KyloVarchar(1024), false));
        Schema s = new Schema(cols);

        List<Object[]> rows = new ArrayList<>();
        // Fake DDL
        String ddl = "CREATE TABLE `" + table + "` (\n" +
                "  `id` int DEFAULT NULL\n" +
                ") ENGINE=KyloDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci";
        rows.add(new Object[] { table, ddl });

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleShowTableStatus(String sql, OutputStream out, byte seq) throws IOException {
        String pattern = null;
        if (sql.toUpperCase().contains("LIKE")) {
            String[] parts = sql.split("(?i)LIKE");
            if (parts.length > 1) {
                pattern = parts[1].trim().replace("'", "");
            }
        }

        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("Name", new com.sylo.kylo.core.structure.KyloVarchar(255),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Engine", new com.sylo.kylo.core.structure.KyloVarchar(64),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Version", new com.sylo.kylo.core.structure.KyloInt(), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Row_format", new com.sylo.kylo.core.structure.KyloVarchar(10),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Rows", new com.sylo.kylo.core.structure.KyloBigInt(), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Avg_row_length", new com.sylo.kylo.core.structure.KyloBigInt(),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Data_length", new com.sylo.kylo.core.structure.KyloBigInt(),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Max_data_length", new com.sylo.kylo.core.structure.KyloBigInt(),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Index_length", new com.sylo.kylo.core.structure.KyloBigInt(),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Data_free", new com.sylo.kylo.core.structure.KyloBigInt(),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Auto_increment", new com.sylo.kylo.core.structure.KyloBigInt(),
                true));
        cols.add(new com.sylo.kylo.core.catalog.Column("Create_time", new com.sylo.kylo.core.structure.KyloVarchar(20),
                true));
        cols.add(new com.sylo.kylo.core.catalog.Column("Update_time", new com.sylo.kylo.core.structure.KyloVarchar(20),
                true));
        cols.add(new com.sylo.kylo.core.catalog.Column("Check_time", new com.sylo.kylo.core.structure.KyloVarchar(20),
                true));
        cols.add(new com.sylo.kylo.core.catalog.Column("Collation", new com.sylo.kylo.core.structure.KyloVarchar(32),
                true));
        cols.add(
                new com.sylo.kylo.core.catalog.Column("Checksum", new com.sylo.kylo.core.structure.KyloBigInt(), true));
        cols.add(new com.sylo.kylo.core.catalog.Column("Create_options",
                new com.sylo.kylo.core.structure.KyloVarchar(255), true));
        cols.add(new com.sylo.kylo.core.catalog.Column("Comment", new com.sylo.kylo.core.structure.KyloVarchar(255),
                true));

        Schema s = new Schema(cols);
        List<Object[]> rows = new ArrayList<>();

        var all = Catalog.getInstance().getTables();
        for (String k : all.keySet()) {
            boolean match = true;
            if (pattern != null) {
                // Determine equality or simple match
                match = k.equals(pattern) || k.equalsIgnoreCase(pattern);
            }

            if (match) {
                rows.add(new Object[] {
                        k, "KyloDB", 10, "Fixed", 0L, 0L, 0L, 0L, 0L, 0L, null, null, null, null, "utf8mb4_0900_ai_ci",
                        null, "", ""
                });
            }
        }
        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleInsert(String sql, OutputStream out, byte seq) {
        try {
            // Pattern: INSERT INTO table (col1, col2) VALUES (val1, val2)
            String pattern = "(?i)INSERT\\s+INTO\\s+['`]?(?:(\\w+)\\.)?(\\w+)['`]?\\s*(?:\\((.*?)\\))?\\s+VALUES\\s*\\((.*)\\)";
            Pattern p = Pattern.compile(pattern, Pattern.DOTALL);
            Matcher m = p.matcher(sql);

            if (m.find()) {
                String db = m.group(1);
                String table = m.group(2);
                String colsStr = m.group(3);
                String valsStr = m.group(4);

                if (db == null)
                    db = session.getCurrentDatabase();
                if (db.equalsIgnoreCase("mysql"))
                    db = "kylo_system";

                String fullTable = db + ":" + table;
                Schema schema = Catalog.getInstance().getTableSchema(fullTable);
                if (schema == null)
                    throw new Exception("Table " + fullTable + " not found.");

                // Value Parser (Very simple, splits by comma)

                String[] valParts = valsStr.split(",");
                Object[] row = new Object[schema.getColumnCount()];

                // If cols specified, map them. If not, assume order.
                if (colsStr == null || colsStr.trim().isEmpty()) {
                    for (int i = 0; i < row.length && i < valParts.length; i++) {
                        row[i] = parseValue(valParts[i], schema.getColumn(i).getType());
                    }
                } else {
                    String[] colParts = colsStr.split(",");
                    for (int i = 0; i < colParts.length; i++) {
                        String cName = colParts[i].trim().replace("`", "");
                        int colIdx = -1;
                        for (int k = 0; k < schema.getColumnCount(); k++) {
                            if (schema.getColumn(k).getName().equalsIgnoreCase(cName)) {
                                colIdx = k;
                                break;
                            }
                        }
                        if (colIdx != -1 && i < valParts.length) {
                            row[colIdx] = parseValue(valParts[i], schema.getColumn(colIdx).getType());
                        }
                    }
                }

                engine.insertTuple(session.getSessionId(), fullTable, row);
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(1, 0), ++seq);
            } else {
                throw new Exception("INSERT syntax parse error (Only basic VALUES supported currently)");
            }

        } catch (Exception e) {
            e.printStackTrace();
            try {
                MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "Insert Failed: " + e.getMessage()), ++seq);
            } catch (IOException ex) {
            }
        }
    }

    private Object parseValue(String raw, com.sylo.kylo.core.structure.KyloType type) {
        String clean = raw.trim();
        if (clean.startsWith("'") && clean.endsWith("'"))
            clean = clean.substring(1, clean.length() - 1);
        else if (clean.startsWith("\"") && clean.endsWith("\""))
            clean = clean.substring(1, clean.length() - 1);

        // Simple type conversion
        // Keep it string for let the Type.validate handle it or conversion
        // Actually Type.validate expects correct Java type usually?
        // KyloInt expects Integer, KyloVarchar String.
        // Let's do basic conversion
        if (type instanceof com.sylo.kylo.core.structure.KyloInt) {
            return Integer.parseInt(clean);
        } else if (type instanceof com.sylo.kylo.core.structure.KyloBigInt) {
            return Long.parseLong(clean);
        } else if (type instanceof com.sylo.kylo.core.structure.KyloBoolean) {
            return Boolean.parseBoolean(clean) || clean.equals("1");
        }
        return clean;
    }

    private void handleCreateTrigger(String sql, OutputStream out, byte seq) {
        try {
            // Mock success for triggers to allow DBeaver to save them
            MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
        } catch (IOException e) {
            e.printStackTrace();
        }
    }

    private void handleCreate(String sql, OutputStream out, byte seq) throws IOException {
        // Basic parser for CREATE TABLE `t` ( col type, ... )
        String pattern = "(?i)CREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?['`]?(?:(\\w+)\\.)?(\\w+)['`]?\\s*\\((.*)\\)";
        Pattern p = Pattern.compile(pattern, Pattern.DOTALL);
        Matcher m = p.matcher(sql);

        if (m.find()) {
            String db = m.group(1);
            String table = m.group(2);
            String body = m.group(3);

            if (db == null)
                db = session.getCurrentDatabase();
            if (db.equalsIgnoreCase("mysql"))
                db = "kylo_system"; // Redirect mysql -> SYSTEM

            String fullTable = db + ":" + table;

            // Revert strict read-only for SYSTEM tables?
            // Actually, allow creating tables in user DBs.

            try {
                List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
                // Split columns by comma, respecting parentheses (for decimals/enums)
                // Simplified split by comma for now (assuming no complex nested types in basic
                // DBeaver create)
                String[] colDefs = body.split(",");

                for (String def : colDefs) {
                    def = def.trim();
                    if (def.isEmpty())
                        continue;

                    // Improved matching for keys/constraints
                    String up = def.toUpperCase();
                    if (up.startsWith("PRIMARY KEY") || up.startsWith("KEY") || up.startsWith("CONSTRAINT")
                            || up.startsWith("FOREIGN KEY") || up.startsWith("UNIQUE KEY") || up.startsWith("INDEX")
                            || up.startsWith("FULLTEXT KEY")) {
                        continue; // Skip keys/constraints
                    }

                    String[] parts = def.split("\\s+");
                    String colName = parts[0].replace("`", "");
                    String typeRaw = parts[1].toUpperCase();

                    com.sylo.kylo.core.structure.KyloType type = new com.sylo.kylo.core.structure.KyloVarchar(255);

                    if (typeRaw.startsWith("INT"))
                        type = new com.sylo.kylo.core.structure.KyloInt();
                    else if (typeRaw.startsWith("BIGINT"))
                        type = new com.sylo.kylo.core.structure.KyloBigInt();
                    else if (typeRaw.startsWith("VARCHAR"))
                        type = new com.sylo.kylo.core.structure.KyloVarchar(255);
                    else if (typeRaw.startsWith("TEXT"))
                        type = new com.sylo.kylo.core.structure.KyloText();
                    else if (typeRaw.startsWith("BOOLEAN") || typeRaw.startsWith("TINYINT"))
                        type = new com.sylo.kylo.core.structure.KyloBoolean();

                    // Check for NOT NULL / DEFAULT parsing? Assuming nullable for simplicity unless
                    // specified
                    boolean nullable = !def.toUpperCase().contains("NOT NULL");

                    cols.add(new com.sylo.kylo.core.catalog.Column(colName, type, nullable));
                }

                if (cols.isEmpty()) {
                    throw new Exception("No columns parsed");
                }

                Catalog.getInstance().createTable(fullTable, new Schema(cols));
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);

            } catch (Exception e) {
                e.printStackTrace();
                MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "Create Table Failed: " + e.getMessage()),
                        ++seq);
            }
        } else {
            // Check if it's CREATE DATABASE
            if (sql.toUpperCase().contains("CREATE DATABASE")) {
                String dbName = sql.replaceAll("(?i)CREATE DATABASE", "").replace(";", "").trim();
                Catalog.getInstance().createDatabase(dbName);
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
            } else {
                MySQLPacket.writePacket(out,
                        PacketBuilder.buildError(1064,
                                "Syntax Error in CREATE (KyloDB only supports CREATE TABLE/USER/TRIGGER) -> " + sql),
                        ++seq);
            }
        }
    }

    private void handleAlterTable(String sql, OutputStream out, byte seq) throws IOException {
        // Simple Parser for: ALTER TABLE tbl ADD CONSTRAINT name TYPE (col) REFERENCES
        // refTbl(refCol)
        String upper = sql.toUpperCase();
        try {
            // Extract Table Name
            String patternTbl = "(?i)ALTER\\s+TABLE\\s+['`]?(?:(\\w+)\\.)?(\\w+)['`]?";
            Pattern pTbl = Pattern.compile(patternTbl);
            Matcher mTbl = pTbl.matcher(sql);
            String db = session.getCurrentDatabase();
            String table = "";

            if (mTbl.find()) {
                if (mTbl.group(1) != null)
                    db = mTbl.group(1);
                table = mTbl.group(2);
            } else {
                throw new Exception("Could not parse table name in ALTER TABLE");
            }

            if (db.equalsIgnoreCase("mysql"))
                db = "kylo_system";
            String fullTable = db + ":" + table;

            if (upper.contains("ADD CONSTRAINT")) {
                // ADD CONSTRAINT name FOREIGN KEY (col) REFERENCES ref(col)
                String constraintPart = sql.substring(upper.indexOf("ADD CONSTRAINT") + 14).trim();
                String constraintName = constraintPart.split("\\s+")[0].replace("`", "");

                if (upper.contains("FOREIGN KEY")) {
                    // Parse FK
                    // Pattern: ... FOREIGN KEY (col) REFERENCES refTbl (refCol)
                    // Allow for quoted identifiers
                    String fkPat = "(?i)FOREIGN\\s+KEY\\s*\\((.+?)\\)\\s*REFERENCES\\s+['`]?(?:(\\w+)\\.)?(\\w+)['`]?\\s*\\((.+?)\\)";
                    Pattern pFk = Pattern.compile(fkPat);
                    Matcher mFk = pFk.matcher(constraintPart);

                    if (mFk.find()) {
                        String childCol = mFk.group(1).replace("`", "").trim();
                        // Support childCol "a,b". For now assume single or take first?
                        // ConstraintManager only supports Single for now.

                        String refDb = mFk.group(2);
                        String refTbl = mFk.group(3);
                        String refCol = mFk.group(4).replace("`", "").trim();

                        String fullRefTable = (refDb != null ? refDb : db) + ":" + refTbl;

                        // Register via Manager
                        // 1. Register in IndexManager for validation (Legacy way? IndexManager has
                        // registerForeignKey)
                        // 2. Register in ConstraintManager for metadata

                        com.sylo.kylo.core.index.IndexManager.getInstance().registerForeignKey(
                                constraintName, fullTable, childCol, fullRefTable, refCol);

                        // Create Lists for Constructor
                        java.util.List<String> childCols = new java.util.ArrayList<>();
                        childCols.add(childCol);
                        java.util.List<String> refCols = new java.util.ArrayList<>();
                        refCols.add(refCol);

                        com.sylo.kylo.core.constraint.Constraint c = new com.sylo.kylo.core.constraint.Constraint(
                                constraintName, fullTable, childCols, fullRefTable, refCols);

                        Catalog.getInstance().addConstraint(c);

                        MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
                        return;
                    }
                } else if (upper.contains("PRIMARY KEY") || upper.contains("UNIQUE")) {
                    com.sylo.kylo.core.constraint.Constraint.Type cType = upper.contains("PRIMARY KEY")
                            ? com.sylo.kylo.core.constraint.Constraint.Type.PRIMARY_KEY
                            : com.sylo.kylo.core.constraint.Constraint.Type.UNIQUE;

                    String colPat = "(?i)(?:PRIMARY\\s+KEY|UNIQUE(?:\\s+KEY)?)\\s*\\((.+?)\\)";
                    Pattern pPk = Pattern.compile(colPat);
                    Matcher mPk = pPk.matcher(constraintPart);

                    if (mPk.find()) {
                        String cols = mPk.group(1).replace("`", "").trim();
                        java.util.List<String> colList = new java.util.ArrayList<>();
                        // handle comma?
                        if (cols.contains(",")) {
                            for (String s : cols.split(","))
                                colList.add(s.trim());
                        } else {
                            colList.add(cols);
                        }

                        com.sylo.kylo.core.constraint.Constraint c = new com.sylo.kylo.core.constraint.Constraint(
                                constraintName, cType, fullTable, colList);
                        Catalog.getInstance().addConstraint(c);
                        MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
                        return;
                    }
                }
            }

            // Fallback for unparsed
            System.out.println("WARN: Unparsed ALTER TABLE: " + sql);
            MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);

        } catch (Exception e) {
            e.printStackTrace();
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "Alter Table Error: " + e.getMessage()), ++seq);
        }
    }

    private void handleCreateIndex(String sql, OutputStream out, byte seq) throws IOException {
        try {
            // CREATE [UNIQUE] INDEX name [USING BTREE] ON table (col)
            // Improved Regex to handle optional "USING BTREE" before ON
            String pattern = "(?i)CREATE\\s+(?:UNIQUE\\s+)?INDEX\\s+['`]?(\\w+)['`]?\\s+(?:USING\\s+\\w+\\s+)?ON\\s+(?:['`]?(\\w+)['`]?\\.)?['`]?(\\w+)['`]?\\s*\\((.+?)\\)";
            Pattern p = Pattern.compile(pattern);
            Matcher m = p.matcher(sql);

            if (m.find()) {
                String indexName = m.group(1);
                String db = m.group(2);
                String table = m.group(3);
                String colParams = m.group(4);

                if (db == null)
                    db = session.getCurrentDatabase();
                if (db.equalsIgnoreCase("mysql"))
                    db = "kylo_system";
                String fullTable = db + ":" + table;

                // Handle possibly complex column params e.g. "col ASC" or "col(10)"
                // For now, take the first token before space/comma/paren
                String col = colParams.split(",")[0].trim();
                col = col.split("\\s+")[0]; // Remove ASC/DESC
                col = col.split("\\(")[0]; // Remove length part if any
                col = col.replace("`", "");

                // Create logic
                // com.sylo.kylo.core.storage.BufferPoolManager bpm = ... (Not needed for
                // metadata registration)

                Catalog.getInstance().getIndexManager().registerIndex(fullTable, col, 9999, indexName); // 9999 dummy

                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
            } else {
                System.err.println("FAILED TO PARSE CREATE INDEX: " + sql);
                throw new Exception("Could not parse CREATE INDEX: " + sql);
            }
        } catch (Exception e) {
            e.printStackTrace();
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "Create Index Failed: " + e.getMessage()),
                    ++seq);
        }
    }

    private void handleShowVariables(String sql, OutputStream out, byte seq) throws IOException {
        String pattern = null;
        if (sql.toUpperCase().contains("LIKE")) {
            String[] parts = sql.split("(?i)LIKE");
            if (parts.length > 1) {
                pattern = parts[1].trim().replace("'", "");
            }
        }

        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("Variable_name",
                new com.sylo.kylo.core.structure.KyloVarchar(100), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Value", new com.sylo.kylo.core.structure.KyloVarchar(255),
                false));
        Schema s = new Schema(cols);

        List<Object[]> rows = new ArrayList<>();
        var vars = session.getAllVariables();
        for (var entry : vars.entrySet()) {
            if (pattern == null || entry.getKey().contains(pattern.replace("%", ""))) {
                rows.add(new Object[] { entry.getKey(), entry.getValue().toString() });
            }
        }

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleMockEmptySet(OutputStream out, byte seq) throws IOException {
        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("MOCK_DUMMY", new com.sylo.kylo.core.structure.KyloVarchar(10),
                true));
        Schema s = new Schema(cols);
        List<Object[]> rows = new ArrayList<>();
        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleShowDatabases(OutputStream out, byte seq) throws IOException {
        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("Database", new com.sylo.kylo.core.structure.KyloVarchar(255),
                false));
        Schema s = new Schema(cols);

        List<Object[]> rows = new ArrayList<>();
        rows.add(new Object[] { "default" });
        rows.add(new Object[] { "kylo_system" });

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleSelectDatabase(OutputStream out, byte seq) throws IOException {
        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("DATABASE()", new com.sylo.kylo.core.structure.KyloVarchar(255),
                false));
        Schema s = new Schema(cols);

        List<Object[]> rows = new ArrayList<>();
        rows.add(new Object[] { "default" });

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleShowProcessList(OutputStream out, byte seq) throws IOException {
        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("Id", new com.sylo.kylo.core.structure.KyloBigInt(), false));
        cols.add(
                new com.sylo.kylo.core.catalog.Column("User", new com.sylo.kylo.core.structure.KyloVarchar(32), false));
        cols.add(
                new com.sylo.kylo.core.catalog.Column("Host", new com.sylo.kylo.core.structure.KyloVarchar(64), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("db", new com.sylo.kylo.core.structure.KyloVarchar(64), true));
        cols.add(new com.sylo.kylo.core.catalog.Column("Command", new com.sylo.kylo.core.structure.KyloVarchar(16),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Time", new com.sylo.kylo.core.structure.KyloInt(), false));
        cols.add(
                new com.sylo.kylo.core.catalog.Column("State", new com.sylo.kylo.core.structure.KyloVarchar(64), true));
        cols.add(
                new com.sylo.kylo.core.catalog.Column("Info", new com.sylo.kylo.core.structure.KyloVarchar(100), true));

        Schema s = new Schema(cols);
        List<Object[]> rows = new ArrayList<>();
        // Mock current connection
        rows.add(new Object[] { 1L, "root", "localhost", session.getCurrentDatabase(), "Query", 0, "executing",
                "SHOW PROCESSLIST" });

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleShowGrants(String sql, OutputStream out, byte seq) throws IOException {
        String user = "root";
        String host = "%";

        Pattern p = Pattern.compile("SHOW\\s+GRANTS\\s+FOR\\s+'(.*?)'@'(.*?)'", Pattern.CASE_INSENSITIVE);
        Matcher m = p.matcher(sql);
        if (m.find()) {
            user = m.group(1);
            host = m.group(2);
        }

        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("Grants for " + user + "@" + host,
                new com.sylo.kylo.core.structure.KyloVarchar(1024), false));

        Schema s = new Schema(cols);
        List<Object[]> rows = new ArrayList<>();
        // Mock full privileges
        rows.add(new Object[] { "GRANT ALL PRIVILEGES ON *.* TO '" + user + "'@'" + host + "' WITH GRANT OPTION" });

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleShowPrivileges(OutputStream out, byte seq) throws IOException {
        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("Privilege", new com.sylo.kylo.core.structure.KyloVarchar(64),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Context", new com.sylo.kylo.core.structure.KyloVarchar(64),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Comment", new com.sylo.kylo.core.structure.KyloVarchar(255),
                false));

        Schema s = new Schema(cols);
        List<Object[]> rows = new ArrayList<>();
        rows.add(new Object[] { "Select", "Tables", "To retrieve rows from table" });
        rows.add(new Object[] { "Insert", "Tables", "To insert data into tables" });
        rows.add(new Object[] { "Update", "Tables", "To update existing rows" });
        rows.add(new Object[] { "Delete", "Tables", "To delete rows" });
        rows.add(new Object[] { "Create", "Databases,Tables,Indexes", "To create new databases and tables" });
        rows.add(new Object[] { "Drop", "Databases,Tables", "To drop databases and tables" });
        rows.add(new Object[] { "Grant", "Tables", "To give to other users those privileges you possess" });
        rows.add(new Object[] { "References", "Tables", "To have references on tables" });
        rows.add(new Object[] { "Index", "Tables", "To create or drop indexes" });
        rows.add(new Object[] { "Alter", "Tables", "To alter the structure of tables" });

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleSumDataLength(OutputStream out, byte seq) throws IOException {
        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("SUM", new com.sylo.kylo.core.structure.KyloBigInt(), false));
        Schema s = new Schema(cols);
        List<Object[]> rows = new ArrayList<>();
        rows.add(new Object[] { 0L });
        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleCreateUser(String sql, OutputStream out, byte seq) throws IOException {
        Pattern p = Pattern.compile("CREATE\\s+USER\\s+'(.*?)'@'(.*?)'\\s+IDENTIFIED\\s+BY\\s+'(.*?)'",
                Pattern.CASE_INSENSITIVE);
        Matcher m = p.matcher(sql);
        if (m.find()) {
            String user = m.group(1);
            String host = m.group(2);
            String pass = m.group(3);

            String hashed = SecurityUtils.hashPassword(pass);

            Object[] tuple = new Object[] { host, user, hashed, false };
            engine.insertTuple("kylo_system:users", tuple);

            MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
        } else {
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "Syntax Error in CREATE USER"), ++seq);
        }
    }

    private void handleDropUser(String sql, OutputStream out, byte seq) throws IOException {
        Pattern p = Pattern.compile("DROP\\s+USER\\s+'(.*?)'@'(.*?)'", Pattern.CASE_INSENSITIVE);
        Matcher m = p.matcher(sql);
        if (m.find()) {
            final String user = m.group(1);
            final String host = m.group(2);

            // 1. Delete from users
            int deleted = engine.deleteTuple("kylo_system:users",
                    t -> t.getValue(1).equals(user) && t.getValue(0).equals(host));

            // 2. Delete from privileges (Cascade)
            engine.deleteTuple("kylo_system:table_privs",
                    t -> t.getValue(1).equals(user) && t.getValue(0).equals(host) || t.getValue(0).equals("%"));
            engine.deleteTuple("kylo_system:db_privs",
                    t -> t.getValue(1).equals(user) && t.getValue(0).equals(host) || t.getValue(0).equals("%"));

            if (deleted > 0) {
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
            } else {
                MySQLPacket.writePacket(out, PacketBuilder.buildError(1396, "User not found"), ++seq);
            }
        } else {
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "Syntax Error in DROP USER"), ++seq);
        }
    }

    private void handleRevoke(String sql, OutputStream out, byte seq) throws IOException {
        Pattern p = Pattern.compile("REVOKE\\s+(.*?)\\s+ON\\s+(.*?)\\.(.*?)\\s+FROM\\s+'(.*?)'",
                Pattern.CASE_INSENSITIVE);
        Matcher m = p.matcher(sql);
        if (m.find()) {

            final String db = m.group(2);
            final String table = m.group(3);
            final String user = m.group(4);
            // Assuming wildcard host for REVOKE if not specified, or just % for now to
            // match GRANT
            // Ideally we parse @'host'

            engine.deleteTuple("kylo_system:table_privs",
                    t -> t.getValue(1).equals(user) && t.getValue(2).equals(db) && t.getValue(3).equals(table));

            MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
        } else {
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "Syntax Error in REVOKE"), ++seq);
        }
    }

    private void handleAlterUser(String sql, OutputStream out, byte seq) throws IOException {
        Pattern p = Pattern.compile("ALTER\\s+USER\\s+'(.*?)'@'(.*?)'\\s+IDENTIFIED\\s+BY\\s+'(.*?)'",
                Pattern.CASE_INSENSITIVE);
        Matcher m = p.matcher(sql);
        if (m.find()) {
            final String user = m.group(1);
            final String host = m.group(2);
            String newPass = m.group(3);
            String newHash = SecurityUtils.hashPassword(newPass);

            // Fetch existing to preserve SuperPriv
            List<Object[]> users = engine.scanTable("kylo_system:users");
            Object[] existing = null;
            for (Object[] row : users) {
                if (row[1].equals(user) && row[0].equals(host)) {
                    existing = row;
                    break;
                }
            }

            if (existing != null) {
                // Update password hash only (Column 2)
                java.util.Map<Integer, Object> changes = new java.util.HashMap<>();
                changes.put(2, newHash);

                engine.updateTuple(session.getSessionId(), "kylo_system:users", changes,
                        t -> t.getValue(1).equals(user) && t.getValue(0).equals(host));

                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
            } else {
                MySQLPacket.writePacket(out, PacketBuilder.buildError(1396, "User not found"), ++seq);
            }
        } else {
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "Syntax Error in ALTER USER"), ++seq);
        }
    }

    private void handleGrant(String sql, OutputStream out, byte seq) throws IOException {
        Pattern p = Pattern.compile("GRANT\\s+(.*?)\\s+ON\\s+(.*?)\\.(.*?)\\s+TO\\s+'(.*?)'", Pattern.CASE_INSENSITIVE);
        Matcher m = p.matcher(sql);
        if (m.find()) {
            String privs = m.group(1);
            String db = m.group(2);
            String table = m.group(3);
            String user = m.group(4);

            String host = "%";
            Object[] tuple = new Object[] { host, user, db, table, privs };
            engine.insertTuple("kylo_system:table_privs", tuple);

            MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
        } else {
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "Syntax Error in GRANT"), ++seq);
        }
    }
}
