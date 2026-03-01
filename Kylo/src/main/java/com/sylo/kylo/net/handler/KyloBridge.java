package com.sylo.kylo.net.handler;

import com.sylo.kylo.core.execution.ExecutionEngine;
import com.sylo.kylo.net.protocol.MySQLPacket;
import com.sylo.kylo.net.protocol.PacketBuilder;
import com.sylo.kylo.core.catalog.Catalog;
import com.sylo.kylo.core.catalog.Schema;
import java.io.IOException;
import java.io.OutputStream;
import java.util.List;
import com.sylo.kylo.core.routine.Routine;
import com.sylo.kylo.core.routine.RoutineManager;
import com.sylo.kylo.core.script.PolyglotScriptExecutor;
import java.util.regex.Matcher;
import java.util.regex.Pattern;
import com.sylo.kylo.core.view.View;
import com.sylo.kylo.core.view.ViewManager;
import com.sylo.kylo.core.trigger.Trigger;
import com.sylo.kylo.core.trigger.TriggerManager;
import com.sylo.kylo.core.event.Event;
import com.sylo.kylo.core.event.EventManager;
import java.time.LocalDateTime;
import java.util.ArrayList;
import java.util.regex.*;
import java.util.Map;
import java.util.HashMap;
import com.sylo.kylo.core.security.SecurityUtils;

@SuppressWarnings("unused") // Private handler methods are called dynamically from executeQuery dispatcher
public class KyloBridge {
    private final ExecutionEngine engine;
    private final ResultSetWriter rsWriter;

    private final com.sylo.kylo.core.security.SecurityInterceptor interceptor;
    private final com.sylo.kylo.core.session.SessionContext session;

    // Session Registry for SHOW PROCESSLIST
    public static final java.util.Map<String, KyloBridge> activeSessions = new java.util.concurrent.ConcurrentHashMap<>();
    private final long connectionTime;
    private String currentUser = "root"; // Default
    private String currentHost = "localhost";

    public KyloBridge(ExecutionEngine engine) {
        this.engine = engine;
        this.rsWriter = new ResultSetWriter();
        this.interceptor = new com.sylo.kylo.core.security.SecurityInterceptor(engine);
        this.session = new com.sylo.kylo.core.session.SessionContext();
        this.connectionTime = System.currentTimeMillis();

        // Register Session
        activeSessions.put(this.toString(), this);
    }

    public void close() {
        activeSessions.remove(this.toString());
    }

    public void setUser(String user, String host) {
        this.currentUser = user;
        this.currentHost = host;
    }

    public String getUser() {
        return currentUser;
    }

    public String getHost() {
        return currentHost;
    }

    public String getDb() {
        return session.getCurrentDatabase();
    }

    public long getTime() {
        return (System.currentTimeMillis() - connectionTime) / 1000;
    }

    public void setCurrentDb(String db) {
        session.setCurrentDatabase(db);
    }

    public void executeQuery(String sql, OutputStream out, byte sequenceId) throws IOException {
        String cleanSql = sql.replaceAll("(?s)/\\*.*?\\*/", "")
                // Aggressive single-line comment strippers removed to prevent breaking strings (like Discord #tags)
                .trim();

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
            // FIX: DBeaver sometimes truncates "SHOW COLUMNS" to "UMNS" (?) or sends
            // partial query
            if (upper.startsWith("UMNS")) {
                System.out.println("DEBUG: Fixing truncated SHOW COLUMNS command");

                // Extract table name from WHERE clause if present
                // Expected: "UMNS WHERE TABLE_NAME='table_name'"
                // Should become: "SHOW COLUMNS FROM INFORMATION_SCHEMA.COLUMNS WHERE
                // TABLE_NAME='table_name'"
                String remainder = cleanSql.substring(4).trim(); // Remove "UMNS"

                if (remainder.toUpperCase().contains("WHERE")) {
                    // Reconstruct as SELECT from INFORMATION_SCHEMA.COLUMNS
                    cleanSql = "SELECT * FROM INFORMATION_SCHEMA.COLUMNS " + remainder;
                } else {
                    // If no WHERE, just show all columns (fallback)
                    cleanSql = "SELECT * FROM INFORMATION_SCHEMA.COLUMNS";
                }

                upper = cleanSql.toUpperCase();
                System.out.println("DEBUG: Reconstructed query: " + cleanSql);
            }

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
            } else if (upper.startsWith("DELETE")) {
                handleDelete(cleanSql, out, sequenceId);
            } else if (upper.startsWith("DROP TABLE")) {
                handleDropTable(cleanSql, out, sequenceId);
            } else if (upper.startsWith("CREATE USER")) {
                handleCreateUser(cleanSql, out, sequenceId);
            } else if (upper.startsWith("CREATE PROCEDURE") || upper.startsWith("CREATE FUNCTION")) {
                System.out.println("DEBUG: Entering handleCreateRoutine for: " + cleanSql);
                handleCreateRoutine(cleanSql, out, sequenceId);
            } else if (upper.startsWith("CREATE VIEW")) {
                handleCreateView(cleanSql, out, sequenceId);
            } else if (upper.startsWith("CREATE TRIGGER")) {
                handleCreateTrigger(cleanSql, out, sequenceId);
            } else if (upper.startsWith("CREATE EVENT")) {
                handleCreateEvent(cleanSql, out, sequenceId);
            } else if (upper.startsWith("DROP PROCEDURE") || upper.startsWith("DROP FUNCTION")) {
                handleDropRoutine(cleanSql, out, sequenceId);
            } else if (upper.startsWith("DROP VIEW")) {
                handleDropView(cleanSql, out, sequenceId);
            } else if (upper.startsWith("DROP TRIGGER")) {
                handleDropTrigger(cleanSql, out, sequenceId);
            } else if (upper.startsWith("DROP EVENT")) {
                handleDropEvent(cleanSql, out, sequenceId);
            } else if (upper.startsWith("CALL")) {
                handleCall(cleanSql, out, sequenceId);
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
                System.out.println("DEBUG: Entering handleCreate (generic) for: " + cleanSql);
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
            } else if (upper.startsWith("SHOW CREATE TRIGGER")) {
                handleShowCreateTrigger(cleanSql, out, sequenceId);
            } else if (upper.startsWith("SHOW TABLES") || upper.startsWith("SHOW FULL TABLES")) {
                handleShowTables(cleanSql, out, sequenceId);
            } else if (upper.startsWith("SHOW TABLE STATUS")) {
                handleShowTableStatus(cleanSql, out, sequenceId);
            } else if (upper.startsWith("SHOW VARIABLES")) {
                handleShowVariables(cleanSql, out, sequenceId);
            } else if (upper.startsWith("SHOW ENGINES")) {
                handleShowEngines(out, sequenceId);
            } else if (upper.startsWith("SHOW COLLATION")) {
                handleShowCollation(out, sequenceId);
            } else if (upper.startsWith("SHOW CHARSET")) {
                handleShowCharset(out, sequenceId);
            } else if (upper.startsWith("SHOW CREATE DATABASE") || upper.startsWith("SHOW CREATE SCHEMA")) {
                handleShowCreateDatabase(cleanSql, out, sequenceId);
            } else if (upper.startsWith("SHOW PLUGINS") || upper.startsWith("SHOW WARNINGS")
                    || upper.startsWith("SHOW STATUS")) {
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
            } else if (upper.startsWith("DROP DATABASE") || upper.startsWith("DROP SCHEMA")) {
                handleDropDatabase(cleanSql, out, sequenceId);
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

    private void handleSelectDatabase(OutputStream out, byte seq) throws IOException {
        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("DATABASE()", new com.sylo.kylo.core.structure.KyloVarchar(255),
                false));
        Schema s = new Schema(cols);

        List<Object[]> rows = new ArrayList<>();
        String current = session.getCurrentDatabase();
        rows.add(new Object[] { current != null ? current : "NULL" });

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleShowCharset(OutputStream out, byte seq) throws IOException {
        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("Charset", new com.sylo.kylo.core.structure.KyloVarchar(64),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Description", new com.sylo.kylo.core.structure.KyloVarchar(255),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Default collation",
                new com.sylo.kylo.core.structure.KyloVarchar(64), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Maxlen", new com.sylo.kylo.core.structure.KyloInt(), false));

        Schema s = new Schema(cols);
        List<Object[]> rows = new ArrayList<>();
        rows.add(new Object[] { "utf8mb4", "UTF-8 Unicode", "utf8mb4_0900_ai_ci", 4 });

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleShowCreateDatabase(String sql, OutputStream out, byte seq) throws IOException {
        String db = session.getCurrentDatabase();
        Pattern p = Pattern.compile("SHOW\\s+CREATE\\s+(?:DATABASE|SCHEMA)\\s+['`]?(\\w+)['`]?",
                Pattern.CASE_INSENSITIVE);
        Matcher m = p.matcher(sql);
        if (m.find()) {
            db = m.group(1);
        }

        if (db == null || db.equals("NULL") || db.equalsIgnoreCase("mysql")) {
            if (db != null && db.equalsIgnoreCase("mysql"))
                db = "kylo_system";
            else if (db == null)
                db = "default";
        }

        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("Database", new com.sylo.kylo.core.structure.KyloVarchar(64),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Create Database",
                new com.sylo.kylo.core.structure.KyloVarchar(1024), false));
        Schema s = new Schema(cols);

        List<Object[]> rows = new ArrayList<>();
        String ddl = "CREATE DATABASE `" + db
                + "` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */";
        rows.add(new Object[] { db, ddl });

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleShowCollation(OutputStream out, byte seq) throws IOException {
        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("Collation", new com.sylo.kylo.core.structure.KyloVarchar(64),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Charset", new com.sylo.kylo.core.structure.KyloVarchar(64),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Id", new com.sylo.kylo.core.structure.KyloInt(), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Default", new com.sylo.kylo.core.structure.KyloVarchar(10),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Compiled", new com.sylo.kylo.core.structure.KyloVarchar(10),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Sortlen", new com.sylo.kylo.core.structure.KyloInt(), false));

        Schema s = new Schema(cols);
        List<Object[]> rows = new ArrayList<>();
        rows.add(new Object[] { "utf8mb4_0900_ai_ci", "utf8mb4", 255, "Yes", "Yes", 1 });

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleShowEngines(OutputStream out, byte seq) throws IOException {
        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("Engine", new com.sylo.kylo.core.structure.KyloVarchar(64),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Support", new com.sylo.kylo.core.structure.KyloVarchar(10),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Comment", new com.sylo.kylo.core.structure.KyloVarchar(80),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Transactions", new com.sylo.kylo.core.structure.KyloVarchar(10),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("XA", new com.sylo.kylo.core.structure.KyloVarchar(10), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Savepoints", new com.sylo.kylo.core.structure.KyloVarchar(10),
                false));

        Schema s = new Schema(cols);
        List<Object[]> rows = new ArrayList<>();
        rows.add(new Object[] { "KyloDB", "DEFAULT", "The Turbo-Core KyloDB Storage Engine", "YES", "NO", "NO" });

        rsWriter.writeResultSet(out, rows, s, seq);
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

            String varName = clean.replace("@@", "").replaceAll("(?i)GLOBAL\\.", "").replaceAll("(?i)SESSION\\.", "")
                    .toLowerCase();
            Object val = session.getVariable(varName); // Look up in context

            // If unknown, return defaults for DBeaver compatibility
            if (val == null) {
                if (varName.contains("time_zone"))
                    val = "SYSTEM";
                else if (varName.contains("system_time_zone"))
                    val = "UTC";
                else if (varName.contains("percent"))
                    val = "0"; // lower_case_table_names etc
                else if (varName.equals("tx_isolation") || varName.equals("transaction_isolation"))
                    val = "REPEATABLE-READ";
                else if (varName.equals("session.auto_increment_increment"))
                    val = 1;
                else if (varName.equals("auto_increment_increment"))
                    val = 1;
                else if (varName.equals("max_allowed_packet"))
                    val = 67108864;
                else if (varName.equals("character_set_server"))
                    val = "utf8mb4";
                else if (varName.equals("collation_server"))
                    val = "utf8mb4_0900_ai_ci";
                else if (varName.equals("init_connect"))
                    val = "";
                else if (varName.equals("license"))
                    val = "GPL";
                else if (varName.equals("lower_case_table_names"))
                    val = 0;
                else if (varName.equals("sql_mode"))
                    val = "";
                else if (varName.equals("performance_schema"))
                    val = 0;
                else
                    val = ""; // Fallback
            }

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
        // Map mysql.* tables to kylo_system.* for DBeaver compatibility
        // MUST be done BEFORE any parsing or security checks
        sql = sql.replaceAll("(?i)\\bmysql\\.user\\b", "kylo_system.users")
                .replaceAll("(?i)\\bmysql\\.db\\b", "kylo_system.db_privs")
                .replaceAll("(?i)\\bmysql\\.tables_priv\\b", "kylo_system.table_privs");

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

        // Detect DBeaver's Complex FK Query (JOINs)
        // Use Regex with DOTALL to handle newlines
        // Pattern: SELECT DISTINCT ... FROM ... KEY_COLUMN_USAGE ... JOIN ...
        // TABLE_CONSTRAINTS
        String dbeaverFkPattern = "(?si).*SELECT\\s+DISTINCT.*FROM\\s+INFORMATION_SCHEMA\\.KEY_COLUMN_USAGE.*JOIN\\s+INFORMATION_SCHEMA\\.TABLE_CONSTRAINTS.*";
        if (sql.matches(dbeaverFkPattern)) {
            System.out.println("DEBUG: Intercepted DBeaver Complex FK Query");
            handleDBeaverFKQuery(sql, out, seq);
            return;
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

            // Intercept Metadata Queries for DBeaver compatibility
            if (virtTable.equalsIgnoreCase("STATISTICS")) {
                List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
                cols.add(new com.sylo.kylo.core.catalog.Column("TABLE_CATALOG",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("TABLE_SCHEMA",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("TABLE_NAME",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("NON_UNIQUE",
                        new com.sylo.kylo.core.structure.KyloBigInt(), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("INDEX_SCHEMA",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("INDEX_NAME",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("SEQ_IN_INDEX",
                        new com.sylo.kylo.core.structure.KyloBigInt(), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("COLUMN_NAME",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("COLLATION",
                        new com.sylo.kylo.core.structure.KyloVarchar(1), true));
                cols.add(new com.sylo.kylo.core.catalog.Column("CARDINALITY",
                        new com.sylo.kylo.core.structure.KyloBigInt(), true));
                cols.add(new com.sylo.kylo.core.catalog.Column("SUB_PART",
                        new com.sylo.kylo.core.structure.KyloBigInt(), true));
                cols.add(new com.sylo.kylo.core.catalog.Column("PACKED",
                        new com.sylo.kylo.core.structure.KyloVarchar(10), true));
                cols.add(new com.sylo.kylo.core.catalog.Column("NULLABLE",
                        new com.sylo.kylo.core.structure.KyloVarchar(3), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("INDEX_TYPE",
                        new com.sylo.kylo.core.structure.KyloVarchar(16), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("COMMENT",
                        new com.sylo.kylo.core.structure.KyloVarchar(16), true));
                cols.add(new com.sylo.kylo.core.catalog.Column("INDEX_COMMENT",
                        new com.sylo.kylo.core.structure.KyloVarchar(1024), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("IS_VISIBLE",
                        new com.sylo.kylo.core.structure.KyloVarchar(3), false));

                schema = new Schema(cols);
                List<Object[]> rows = new ArrayList<>();

                // Real Implementation
                var idxMgr = Catalog.getInstance().getIndexManager();
                var allIndexes = idxMgr.getIndexNames(); // Returns "Table.Column" keys.

                // Filtering Logic (Robust Regex)
                String sqlUpper = sql.toUpperCase();
                String targetTable = null;
                String targetSchema = null;

                java.util.regex.Pattern pTbl = java.util.regex.Pattern
                        .compile("(?i)(?:^|[\\s\\.])TABLE_NAME\\s*=\\s*['\"]([^'\"]+)['\"]");
                java.util.regex.Matcher mTbl = pTbl.matcher(sql);
                if (mTbl.find())
                    targetTable = mTbl.group(1);

                java.util.regex.Pattern pSch = java.util.regex.Pattern
                        .compile("(?i)(?:^|[\\s\\.])TABLE_SCHEMA\\s*=\\s*['\"]([^'\"]+)['\"]");
                java.util.regex.Matcher mSch = pSch.matcher(sql);
                if (mSch.find())
                    targetSchema = mSch.group(1);

                for (String key : allIndexes) {
                    String[] partsStat = key.split("\\.");
                    if (partsStat.length < 2)
                        continue;

                    String tableNameFull = partsStat[0];
                    String colName = partsStat[1];
                    String indexName = idxMgr.getIndexName(key);
                    boolean isPrimary = indexName.equalsIgnoreCase("PRIMARY");

                    String dbName = "kylo_system";
                    String tName = tableNameFull;
                    if (tableNameFull.contains(":")) {
                        String[] tParts = tableNameFull.split(":");
                        dbName = tParts[0];
                        tName = tParts[1];
                    } else if (session.getCurrentDatabase() != null) {
                        dbName = session.getCurrentDatabase();
                    }

                    // Apply Filter
                    if (targetTable != null && !tName.equalsIgnoreCase(targetTable))
                        continue;
                    if (targetSchema != null && !dbName.equalsIgnoreCase(targetSchema))
                        continue;

                    rows.add(new Object[] {
                            "def", dbName, tName,
                            (isPrimary ? 0L : 1L), // NON_UNIQUE
                            dbName, indexName,
                            1L, // SEQ_IN_INDEX (Single col assumption for now)
                            colName, "A", null, null, null, "YES", "BTREE", "", "", "YES"
                    });
                }

                rsWriter.writeResultSet(out, rows, schema, seq);
                return;
            } else if (virtTable.equalsIgnoreCase("KEY_COLUMN_USAGE")) {
                List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
                cols.add(new com.sylo.kylo.core.catalog.Column("CONSTRAINT_CATALOG",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("CONSTRAINT_SCHEMA",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("CONSTRAINT_NAME",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("TABLE_CATALOG",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("TABLE_SCHEMA",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("TABLE_NAME",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("COLUMN_NAME",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("ORDINAL_POSITION",
                        new com.sylo.kylo.core.structure.KyloBigInt(), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("POSITION_IN_UNIQUE_CONSTRAINT",
                        new com.sylo.kylo.core.structure.KyloBigInt(), true));
                cols.add(new com.sylo.kylo.core.catalog.Column("REFERENCED_TABLE_SCHEMA",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), true));
                cols.add(new com.sylo.kylo.core.catalog.Column("REFERENCED_TABLE_NAME",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), true));
                cols.add(new com.sylo.kylo.core.catalog.Column("REFERENCED_COLUMN_NAME",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), true));

                schema = new Schema(cols);
                List<Object[]> rows = new ArrayList<>();
                // Real Implementation
                var cMgr = Catalog.getInstance().getConstraintManager();
                var allTables = Catalog.getInstance().getAllTableNames();

                // Filtering Logic
                String sqlUpper = sql.toUpperCase();
                String targetTable = null;
                String targetSchema = null;

                // Regex handles prefixes (A.TABLE_NAME) and different providers
                java.util.regex.Pattern pTbl = java.util.regex.Pattern
                        .compile("(?i)(?:^|[\\s\\.])TABLE_NAME\\s*=\\s*['\"]([^'\"]+)['\"]");
                java.util.regex.Matcher mTbl = pTbl.matcher(sql);
                if (mTbl.find())
                    targetTable = mTbl.group(1);

                java.util.regex.Pattern pSch = java.util.regex.Pattern
                        .compile("(?i)(?:^|[\\s\\.])TABLE_SCHEMA\\s*=\\s*['\"]([^'\"]+)['\"]");
                java.util.regex.Matcher mSch = pSch.matcher(sql);
                if (mSch.find())
                    targetSchema = mSch.group(1);

                java.util.regex.Pattern pCSch = java.util.regex.Pattern
                        .compile("(?i)(?:^|[\\s\\.])CONSTRAINT_SCHEMA\\s*=\\s*['\"]([^'\"]+)['\"]");
                java.util.regex.Matcher mCSch = pCSch.matcher(sql);
                if (mCSch.find())
                    targetSchema = mCSch.group(1);

                for (String tFull : allTables) {
                    List<com.sylo.kylo.core.constraint.Constraint> consts = cMgr.getConstraints(tFull);
                    String dbName = "kylo_system";
                    String tName = tFull;
                    if (tFull.contains(":")) {
                        String[] partsKCU = tFull.split(":");
                        dbName = partsKCU[0];
                        tName = partsKCU[1];
                    }

                    // Apply Filter
                    if (targetTable != null && !tName.equalsIgnoreCase(targetTable))
                        continue;
                    if (targetSchema != null && !dbName.equalsIgnoreCase(targetSchema))
                        continue;

                    for (com.sylo.kylo.core.constraint.Constraint c : consts) {
                        if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.PRIMARY_KEY
                                || c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.FOREIGN_KEY
                                || c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.UNIQUE) {
                            // Iterate columns
                            for (int i = 0; i < c.getColumns().size(); i++) {
                                String col = c.getColumns().get(i);
                                String refDb = null;
                                String refTbl = null;
                                String refCol = null;

                                if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.FOREIGN_KEY) {
                                    String refFull = c.getRefTable();
                                    // CRITICAL SAFETY: If FK has no ref table, skip or it causes NPE in DBeaver
                                    if (refFull == null)
                                        continue;

                                    if (refFull.contains(":")) {
                                        String[] pRef = refFull.split(":");
                                        refDb = pRef[0];
                                        refTbl = pRef[1];
                                    } else {
                                        refDb = dbName;
                                        refTbl = refFull;
                                    }

                                    // CRITICAL SAFETY: Ensure Ref Col exists
                                    if (c.getRefColumns() != null && i < c.getRefColumns().size()) {
                                        refCol = c.getRefColumns().get(i);
                                    } else {
                                        refCol = "UNKNOWN_COL";
                                    }
                                }

                                Long posInUnique = null;
                                if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.FOREIGN_KEY) {
                                    posInUnique = (long) (i + 1);
                                }

                                rows.add(new Object[] {
                                        "def", dbName, c.getName(),
                                        "def", dbName, tName,
                                        col, (long) (i + 1), posInUnique,
                                        refDb, refTbl, refCol
                                });
                            }
                        }
                    }
                }

                rsWriter.writeResultSet(out, rows, schema, seq);
                return;
            } else if (virtTable.equalsIgnoreCase("TABLE_CONSTRAINTS")) {
                List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
                cols.add(new com.sylo.kylo.core.catalog.Column("CONSTRAINT_CATALOG",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("CONSTRAINT_SCHEMA",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("CONSTRAINT_NAME",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("TABLE_SCHEMA",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("TABLE_NAME",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("CONSTRAINT_TYPE",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));

                schema = new Schema(cols);
                List<Object[]> rows = new ArrayList<>();

                // Real Implementation
                var cMgr = Catalog.getInstance().getConstraintManager();
                var allTables = Catalog.getInstance().getAllTableNames();

                // Filtering Logic
                String sqlUpper = sql.toUpperCase();
                String targetTable = null;
                String targetSchema = null;

                // Regex handles prefixes (A.TABLE_NAME) and different providers
                java.util.regex.Pattern pTbl2 = java.util.regex.Pattern
                        .compile("(?i)(?:^|[\\s\\.])TABLE_NAME\\s*=\\s*['\"]([^'\"]+)['\"]");
                java.util.regex.Matcher mTbl2 = pTbl2.matcher(sql);
                if (mTbl2.find())
                    targetTable = mTbl2.group(1);

                java.util.regex.Pattern pSch2 = java.util.regex.Pattern
                        .compile("(?i)(?:^|[\\s\\.])TABLE_SCHEMA\\s*=\\s*['\"]([^'\"]+)['\"]");
                java.util.regex.Matcher mSch2 = pSch2.matcher(sql);
                if (mSch2.find())
                    targetSchema = mSch2.group(1);

                java.util.regex.Pattern pCSch2 = java.util.regex.Pattern
                        .compile("(?i)(?:^|[\\s\\.])CONSTRAINT_SCHEMA\\s*=\\s*['\"]([^'\"]+)['\"]");
                java.util.regex.Matcher mCSch2 = pCSch2.matcher(sql);
                if (mCSch2.find())
                    targetSchema = mCSch2.group(1);

                for (String tFull : allTables) {
                    List<com.sylo.kylo.core.constraint.Constraint> consts = cMgr.getConstraints(tFull);
                    String dbName = "kylo_system";
                    String tName = tFull;
                    if (tFull.contains(":")) {
                        String[] partsTC = tFull.split(":");
                        dbName = partsTC[0];
                        tName = partsTC[1];
                    }

                    // Apply Filter
                    if (targetTable != null && !tName.equalsIgnoreCase(targetTable))
                        continue;
                    if (targetSchema != null && !dbName.equalsIgnoreCase(targetSchema))
                        continue;

                    for (com.sylo.kylo.core.constraint.Constraint c : consts) {
                        String type = "UNKNOWN";
                        if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.PRIMARY_KEY)
                            type = "PRIMARY KEY";
                        else if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.UNIQUE)
                            type = "UNIQUE";
                        else if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.FOREIGN_KEY)
                            type = "FOREIGN KEY";

                        rows.add(new Object[] {
                                "def", dbName, c.getName(),
                                dbName, tName, type
                        });
                    }
                }

                rsWriter.writeResultSet(out, rows, schema, seq);
                return;

            } else if (virtTable.equalsIgnoreCase("ROUTINES")) {
                handleInformationSchemaRoutines(out, seq, sql);
                return;
            } else if (virtTable.equalsIgnoreCase("TRIGGERS")) {
                handleInformationSchemaTriggers(out, seq, sql);
                return;
            } else if (virtTable.equalsIgnoreCase("EVENTS")) {
                handleInformationSchemaEvents(out, seq, sql);
                return;
            } else if (virtTable.equalsIgnoreCase("REFERENTIAL_CONSTRAINTS")) {
                // DBeaver needs this for FK discovery
                List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
                cols.add(new com.sylo.kylo.core.catalog.Column("CONSTRAINT_CATALOG",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("CONSTRAINT_SCHEMA",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("CONSTRAINT_NAME",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("UNIQUE_CONSTRAINT_CATALOG",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("UNIQUE_CONSTRAINT_SCHEMA",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("UNIQUE_CONSTRAINT_NAME",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), true));
                cols.add(new com.sylo.kylo.core.catalog.Column("MATCH_OPTION",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("UPDATE_RULE",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("DELETE_RULE",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("TABLE_NAME",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));
                cols.add(new com.sylo.kylo.core.catalog.Column("REFERENCED_TABLE_NAME",
                        new com.sylo.kylo.core.structure.KyloVarchar(64), false));

                schema = new Schema(cols);
                List<Object[]> rows = new ArrayList<>();

                // Populate from ConstraintManager
                var cMgr = Catalog.getInstance().getConstraintManager();
                var allTables = Catalog.getInstance().getAllTableNames();

                // Filtering Logic
                String sqlUpper = sql.toUpperCase();
                String targetTable = null;
                String targetSchema = null;

                // Using same robust regex logic
                java.util.regex.Pattern pTbl3 = java.util.regex.Pattern
                        .compile("(?i)(?:^|[\\s\\.])TABLE_NAME\\s*=\\s*['\"]([^'\"]+)['\"]");
                java.util.regex.Matcher mTbl3 = pTbl3.matcher(sql);
                if (mTbl3.find())
                    targetTable = mTbl3.group(1);

                java.util.regex.Pattern pSch3 = java.util.regex.Pattern
                        .compile("(?i)(?:^|[\\s\\.])TABLE_SCHEMA\\s*=\\s*['\"]([^'\"]+)['\"]");
                java.util.regex.Matcher mSch3 = pSch3.matcher(sql);
                if (mSch3.find())
                    targetSchema = mSch3.group(1);

                java.util.regex.Pattern pCSch3 = java.util.regex.Pattern
                        .compile("(?i)(?:^|[\\s\\.])CONSTRAINT_SCHEMA\\s*=\\s*['\"]([^'\"]+)['\"]");
                java.util.regex.Matcher mCSch3 = pCSch3.matcher(sql);
                if (mCSch3.find())
                    targetSchema = mCSch3.group(1);

                for (String tFull : allTables) {
                    List<com.sylo.kylo.core.constraint.Constraint> consts = cMgr.getConstraints(tFull);
                    String dbName = "kylo_system";
                    String tName = tFull;
                    if (tFull.contains(":")) {
                        String[] refParts = tFull.split(":");
                        dbName = refParts[0];
                        tName = refParts[1];
                    }

                    // Apply Filter
                    if (targetTable != null && !tName.equalsIgnoreCase(targetTable))
                        continue;
                    if (targetSchema != null && !dbName.equalsIgnoreCase(targetSchema))
                        continue;

                    for (com.sylo.kylo.core.constraint.Constraint c : consts) {
                        if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.FOREIGN_KEY) {
                            String refTable = c.getRefTable();
                            if (refTable == null)
                                continue; // SAFETY

                            String refDb = dbName;
                            String refTableName = refTable;
                            if (refTable.contains(":")) {
                                String[] refParts2 = refTable.split(":");
                                refDb = refParts2[0];
                                refTableName = refParts2[1];
                            }

                            // Get the PK constraint name from referenced table
                            String pkConstraintName = "PRIMARY";
                            List<com.sylo.kylo.core.constraint.Constraint> refConsts = cMgr.getConstraints(refTable);
                            boolean foundPk = false;
                            if (refConsts != null) {
                                for (com.sylo.kylo.core.constraint.Constraint rc : refConsts) {
                                    if (rc.getType() == com.sylo.kylo.core.constraint.Constraint.Type.PRIMARY_KEY) {
                                        pkConstraintName = rc.getName();
                                        foundPk = true;
                                        break;
                                    }
                                }
                                // Fallback: Look for UNIQUE if no PK
                                if (!foundPk) {
                                    for (com.sylo.kylo.core.constraint.Constraint rc : refConsts) {
                                        if (rc.getType() == com.sylo.kylo.core.constraint.Constraint.Type.UNIQUE) {
                                            pkConstraintName = rc.getName(); // Use Unique Key as target
                                            break;
                                        }
                                    }
                                }
                            }

                            rows.add(new Object[] {
                                    "def", dbName, c.getName(),
                                    "def", refDb, pkConstraintName,
                                    "NONE",
                                    c.getOnUpdate() != null ? c.getOnUpdate() : "NO ACTION",
                                    c.getOnDelete() != null ? c.getOnDelete() : "NO ACTION",
                                    tName, refTableName
                            });
                        }
                    }
                }

                rsWriter.writeResultSet(out, rows, schema, seq);
                return;
            } else if (virtTable.equalsIgnoreCase("PARAMETERS") || virtTable.equalsIgnoreCase("PARTITIONS")) {
                // Mock empty for other optional metadata
                List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
                cols.add(new com.sylo.kylo.core.catalog.Column("DUMMY", new com.sylo.kylo.core.structure.KyloVarchar(1),
                        true));
                schema = new Schema(cols);
                List<Object[]> rows = new ArrayList<>();
                rsWriter.writeResultSet(out, rows, schema, seq);
                return;
            }

            // Get schema from Provider depending on implementation
            schema = com.sylo.kylo.core.sys.SystemTableProvider.getSchema(virtTable);
        } else {
            // Security Check
            // We use the fullTable "db:table" to check permission? Or just "table"?
            // Simplifying:
            String dbCheck = fullTable.split(":")[0];
            String tblCheck = fullTable.split(":")[1];

            // Bypass security check for kylo_system tables (metadata access)
            if (!dbCheck.equalsIgnoreCase("kylo_system")) {
                interceptor.checkPermission(dbCheck, tblCheck, "SELECT");
            }
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

                // Split by AND (Robust Tokenizer)
                String[] rawConditions = splitByAndIgnoringQuotes(whereClause);

                for (String cond : rawConditions) {
                    if (!cond.contains("="))
                        continue;
                    String[] condParts = cond.split("=");
                    String colRaw = condParts[0].trim();
                    String valRaw = condParts[1].trim();

                    // Clean value (remove junk like LIMIT, ORDER BY if it's the last one)
                    // (Only applies to the last condition really, but loop logic handles it if
                    // rigorous)
                    // Clean value (remove junk like LIMIT, ORDER BY if it's the last one)
                    // Use Regex to handle newlines/spaces
                    String[] separators = new String[] { "LIMIT", "GROUP\\s+BY", "ORDER\\s+BY" };
                    for (String sep : separators) {
                        java.util.regex.Pattern pSep = java.util.regex.Pattern.compile("(?i)\\s+" + sep + "\\s+");
                        java.util.regex.Matcher mSep = pSep.matcher(valRaw);
                        if (mSep.find()) {
                            valRaw = valRaw.substring(0, mSep.start()).trim();
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

    private void handleDelete(String sql, OutputStream out, byte seq) throws IOException {
        try {
            // Pattern: DELETE FROM [db.]table WHERE ...
            String pattern = "(?i)DELETE\\s+FROM\\s+['`]?(?:(\\w+)\\.)?(\\w+)['`]?(\\s+WHERE\\s+.*)?$";
            Pattern p = Pattern.compile(pattern, Pattern.DOTALL);
            Matcher m = p.matcher(sql);

            if (m.find()) {
                String db = m.group(1);
                String table = m.group(2);
                String whereClauseRaw = m.group(3);

                if (db == null)
                    db = session.getCurrentDatabase();
                if (db.equalsIgnoreCase("mysql"))
                    db = "kylo_system";

                String fullTable = db + ":" + table;
                Schema schema = Catalog.getInstance().getTableSchema(fullTable);
                if (schema == null)
                    throw new Exception("Table " + fullTable + " not found");

                // Parse WHERE (Naive equality check only)
                final int filterColIdx;
                final Object filterVal;

                if (whereClauseRaw != null) {
                    String whereClean = whereClauseRaw.trim().substring(5).trim(); // remove WHERE
                    if (whereClean.contains("=")) {
                        String[] cond = whereClean.split("=");
                        String cName = cond[0].trim().replace("`", "");
                        if (cName.contains("."))
                            cName = cName.substring(cName.lastIndexOf(".") + 1);
                        String vRaw = cond[1].trim();
                        
                        // Strip trailing junk like LIMIT
                        String[] separators = new String[] { " LIMIT ", " GROUP BY ", " ORDER BY " };
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
                    filterVal = null;
                }

                java.util.function.Predicate<com.sylo.kylo.core.structure.Tuple> predicate = t -> {
                    if (whereClauseRaw == null)
                        return true; // DELETE ALL (TRUNCATE style)
                    if (filterColIdx == -1)
                        return false;

                    Object val = t.getValue(filterColIdx);
                    if (val == null)
                        return filterVal == null;
                    return val.toString().equals(filterVal.toString());
                };

                int deletedCount = engine.deleteTuple(session.getSessionId(), fullTable, predicate);
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(deletedCount, 0), ++seq);
            } else {
                throw new Exception("DELETE syntax not supported yet");
            }
        } catch (Exception e) {
            e.printStackTrace();
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "DELETE Error: " + e.getMessage()), ++seq);
        }
    }

    private void handleDropTable(String sql, OutputStream out, byte seq) throws IOException {
        try {
            // Pattern: DROP TABLE [IF EXISTS] [db.]table
            String pattern = "(?i)DROP\\s+TABLE\\s+(?:IF\\s+EXISTS\\s+)?['`]?(?:(\\w+)\\.)?(\\w+)['`]?";
            Matcher m = Pattern.compile(pattern).matcher(sql);
            if (m.find()) {
                String db = m.group(1);
                String table = m.group(2);
                if (db == null) db = session.getCurrentDatabase();
                if (db != null && db.equalsIgnoreCase("mysql")) db = "kylo_system";
                
                String fullTable = (db != null ? db : "Default") + ":" + table;
                Catalog.getInstance().removeTable(fullTable);
                engine.dropTable(fullTable);
                
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
            } else {
                throw new Exception("Invalid DROP TABLE syntax");
            }
        } catch (Exception e) {
            e.printStackTrace();
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "DROP TABLE Error: " + e.getMessage()), ++seq);
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
                // Keep as is for fullTable resolution or handle below
            }
        }

        String fullTable = "";
        String db = session.getCurrentDatabase();
        if (table.contains(".")) {
            String[] parts = table.split("\\.");
            if (parts[0].equalsIgnoreCase("mysql"))
                parts[0] = "kylo_system";
            fullTable = parts[0] + ":" + parts[1];
            table = parts[1]; // for display
        } else {
            if (db.equalsIgnoreCase("mysql"))
                db = "kylo_system";
            fullTable = db + ":" + table;
        }

        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("Table", new com.sylo.kylo.core.structure.KyloVarchar(64),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Create Table",
                new com.sylo.kylo.core.structure.KyloVarchar(1024), false));
        Schema s = new Schema(cols);

        List<Object[]> rows = new ArrayList<>();

        String ddl = Catalog.getInstance().generateDDL(fullTable);
        if (ddl == null) {
            // Table not found
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1146, "Table '" + fullTable + "' doesn't exist"),
                    ++seq);
            return;
        }

        rows.add(new Object[] { table, ddl });

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleShowCreateTrigger(String sql, OutputStream out, byte seq) throws IOException {
        // Parse trigger name: SHOW CREATE TRIGGER [db.]trigger_name
        String triggerName = "";
        String db = session.getCurrentDatabase();

        Pattern p = Pattern.compile("SHOW\\s+CREATE\\s+TRIGGER\\s+(?:([`'\\w]+)\\.)?([`'\\w]+)",
                Pattern.CASE_INSENSITIVE);
        Matcher m = p.matcher(sql);
        if (m.find()) {
            if (m.group(1) != null) {
                db = m.group(1).replace("`", "").replace("'", "");
            }
            triggerName = m.group(2).replace("`", "").replace("'", "");
        }

        // Find trigger in TriggerManager
        var triggerMgr = Catalog.getInstance().getTriggerManager();
        com.sylo.kylo.core.trigger.Trigger trigger = null;

        // Case-insensitive lookup with debug logging
        System.out.println("DEBUG: Looking for trigger '" + triggerName + "' in schema '" + db + "'");
        for (com.sylo.kylo.core.trigger.Trigger t : triggerMgr.getAllTriggers().values()) {
            System.out.println("  Checking: name='" + t.getName() + "' schema='" + t.getTriggerSchema() + "'");
            if (t.getName().equalsIgnoreCase(triggerName) && t.getTriggerSchema().equalsIgnoreCase(db)) {
                trigger = t;
                break;
            }
        }

        if (trigger == null) {
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1360, "Trigger '" + triggerName + "' doesn't exist"),
                    ++seq);
            return;
        }

        // Build CREATE TRIGGER statement
        StringBuilder ddl = new StringBuilder();
        ddl.append("CREATE TRIGGER `").append(trigger.getName()).append("` ");
        ddl.append(trigger.getTiming()).append(" ");
        ddl.append(trigger.getEvent()).append(" ON `");
        ddl.append(trigger.getEventTable()).append("` FOR EACH ROW ");
        ddl.append(trigger.getStatement());

        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("Trigger", new com.sylo.kylo.core.structure.KyloVarchar(64),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("sql_mode", new com.sylo.kylo.core.structure.KyloVarchar(256),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("SQL Original Statement",
                new com.sylo.kylo.core.structure.KyloVarchar(2048), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("character_set_client",
                new com.sylo.kylo.core.structure.KyloVarchar(32), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("collation_connection",
                new com.sylo.kylo.core.structure.KyloVarchar(32), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Database Collation",
                new com.sylo.kylo.core.structure.KyloVarchar(32), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("Created", new com.sylo.kylo.core.structure.KyloDateTime(),
                true));

        Schema s = new Schema(cols);
        List<Object[]> rows = new ArrayList<>();
        rows.add(new Object[] {
                trigger.getName(),
                "TRADITIONAL",
                ddl.toString(),
                "utf8mb4",
                "utf8mb4_0900_ai_ci",
                "utf8mb4_0900_ai_ci",
                null
        });

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

        // Real Stats from BufferPool
        for (String k : all.keySet()) {
            boolean match = true;
            if (pattern != null) {
                // Determine equality or simple match
                match = k.equals(pattern) || k.equalsIgnoreCase(pattern);
            }

            if (match) {
                long totalPages = 0;
                try {
                    // Normalize table name request to match storage key
                    // Catalog keys are "db:table" or just "table"
                    // ExecutionEngine expects the key used in Catalog
                    totalPages = engine.getTablePageCount(k);
                } catch (Exception e) {
                    // Table might not be loaded or created in engine yet
                    totalPages = 0;
                }

                long dataLength = totalPages * 4096;
                long estimatedRows = totalPages * 100; // Rough estimate

                rows.add(new Object[] {
                        k, "KyloDB", 10, "Fixed", estimatedRows, estimatedRows / 2, dataLength, 0L, 0L, 0L, null, null,
                        null, null, "utf8mb4_0900_ai_ci",
                        null, "", ""
                });
            }
        }
        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleDBeaverFKQuery(String sql, OutputStream out, byte seq) throws IOException {
        try {
            System.out.println("DEBUG: Starting handleDBeaverFKQuery processing...");

            // DBeaver Query Projection:
            // PKTABLE_CAT, PKTABLE_SCHEM, PKTABLE_NAME, PKCOLUMN_NAME, FKTABLE_CAT,
            // FKTABLE_SCHEM, FKTABLE_NAME, FKCOLUMN_NAME, KEY_SEQ, UPDATE_RULE,
            // DELETE_RULE, FK_NAME, PK_NAME, DEFERRABILITY

            List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
            cols.add(new com.sylo.kylo.core.catalog.Column("PKTABLE_CAT",
                    new com.sylo.kylo.core.structure.KyloVarchar(64),
                    true));
            cols.add(new com.sylo.kylo.core.catalog.Column("PKTABLE_SCHEM",
                    new com.sylo.kylo.core.structure.KyloVarchar(64), true));
            cols.add(new com.sylo.kylo.core.catalog.Column("PKTABLE_NAME",
                    new com.sylo.kylo.core.structure.KyloVarchar(64),
                    true));
            cols.add(new com.sylo.kylo.core.catalog.Column("PKCOLUMN_NAME",
                    new com.sylo.kylo.core.structure.KyloVarchar(64), true));
            cols.add(new com.sylo.kylo.core.catalog.Column("FKTABLE_CAT",
                    new com.sylo.kylo.core.structure.KyloVarchar(64),
                    true));
            cols.add(new com.sylo.kylo.core.catalog.Column("FKTABLE_SCHEM",
                    new com.sylo.kylo.core.structure.KyloVarchar(64), true));
            cols.add(new com.sylo.kylo.core.catalog.Column("FKTABLE_NAME",
                    new com.sylo.kylo.core.structure.KyloVarchar(64),
                    true));
            cols.add(new com.sylo.kylo.core.catalog.Column("FKCOLUMN_NAME",
                    new com.sylo.kylo.core.structure.KyloVarchar(64), true));
            cols.add(new com.sylo.kylo.core.catalog.Column("KEY_SEQ", new com.sylo.kylo.core.structure.KyloBigInt(),
                    true));
            cols.add(
                    new com.sylo.kylo.core.catalog.Column("UPDATE_RULE", new com.sylo.kylo.core.structure.KyloBigInt(),
                            true));
            cols.add(
                    new com.sylo.kylo.core.catalog.Column("DELETE_RULE", new com.sylo.kylo.core.structure.KyloBigInt(),
                            true));
            cols.add(new com.sylo.kylo.core.catalog.Column("FK_NAME", new com.sylo.kylo.core.structure.KyloVarchar(64),
                    true));
            cols.add(new com.sylo.kylo.core.catalog.Column("PK_NAME", new com.sylo.kylo.core.structure.KyloVarchar(64),
                    true));
            cols.add(new com.sylo.kylo.core.catalog.Column("DEFERRABILITY",
                    new com.sylo.kylo.core.structure.KyloBigInt(),
                    true));

            Schema schema = new Schema(cols);
            List<Object[]> rows = new ArrayList<>();

            // Parse Target Table from Query: A.TABLE_NAME='NewTable1' and
            // A.TABLE_SCHEMA='67'
            String targetTable = null;
            String targetSchema = null;

            java.util.regex.Pattern pTbl = java.util.regex.Pattern.compile("A\\.TABLE_NAME\\s*=\\s*['\"]([^'\"]+)['\"]",
                    java.util.regex.Pattern.CASE_INSENSITIVE);
            java.util.regex.Matcher mTbl = pTbl.matcher(sql);
            if (mTbl.find())
                targetTable = mTbl.group(1);

            java.util.regex.Pattern pSch = java.util.regex.Pattern.compile(
                    "A\\.TABLE_SCHEMA\\s*=\\s*['\"]([^'\"]+)['\"]",
                    java.util.regex.Pattern.CASE_INSENSITIVE);
            java.util.regex.Matcher mSch = pSch.matcher(sql);
            if (mSch.find())
                targetSchema = mSch.group(1);

            if (targetTable != null) {
                String db = targetSchema != null ? targetSchema : session.getCurrentDatabase();
                if (db == null)
                    db = "Default";
                String fullTable = db + ":" + targetTable;

                com.sylo.kylo.core.constraint.ConstraintManager cMgr = com.sylo.kylo.core.constraint.ConstraintManager
                        .getInstance();
                List<com.sylo.kylo.core.constraint.Constraint> consts = cMgr.getConstraints(fullTable);

                if (consts == null || consts.isEmpty()) {
                    System.out.println("❌ DIAGNOSTIC: No constraints found for key: " + fullTable);
                    System.out.println("   Parsed Target Table: " + targetTable);
                    System.out.println("   Parsed Target Schema: " + targetSchema);
                    System.out.println("   Session DB: " + session.getCurrentDatabase());
                    System.out.println("   Available Keys in ConstraintManager:");
                    for (String k : cMgr.getAllKeys()) {
                        System.out.println("      -> " + k);
                    }
                }

                if (consts != null) {
                    boolean foundFK = false;
                    for (com.sylo.kylo.core.constraint.Constraint c : consts) {
                        if (c.getType() != com.sylo.kylo.core.constraint.Constraint.Type.FOREIGN_KEY) {
                            System.out.println("DEBUG DBeaver FK: Skipping non-FK constraint: " + c.getName()
                                    + " Type: " + c.getType());
                            continue;
                        }
                        foundFK = true;

                        // if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.FOREIGN_KEY)
                        // {
                        String refFull = c.getRefTable();
                        if (refFull == null)
                            continue;

                        String refDb = db;
                        String refTbl = refFull;
                        if (refFull.contains(":")) {
                            String[] p = refFull.split(":");
                            refDb = p[0];
                            refTbl = p[1];
                        }

                        // Parse Rule Codes
                        // CASCADE=0, RESTRICT/NO ACTION=1, SET NULL=2, SET DEFAULT=4
                        int updateRule = 1;
                        int deleteRule = 1;
                        if (c.getOnUpdate() != null) {
                            String r = c.getOnUpdate().toUpperCase();
                            if (r.contains("CASCADE"))
                                updateRule = 0;
                            else if (r.contains("SET NULL"))
                                updateRule = 2;
                        }
                        if (c.getOnDelete() != null) {
                            String r = c.getOnDelete().toUpperCase();
                            if (r.contains("CASCADE"))
                                deleteRule = 0;
                            else if (r.contains("SET NULL"))
                                deleteRule = 2;
                        }

                        // Use simple "PRIMARY" or existing unique constraint name?
                        // DBeaver seems to want the Unique Key Name of the Referenced Table.
                        // We will default to "PRIMARY" for now to avoid extra lookups unless crucial.
                        String pkName = "PRIMARY";

                        List<String> childCols = c.getColumns();
                        if (childCols == null) {
                            System.out.println("Warning: Constraint " + c.getName() + " has no columns.");
                            continue;
                        }

                        for (int i = 0; i < childCols.size(); i++) {
                            String col = childCols.get(i);
                            String refCol = "Unknown";

                            if (c.getRefColumns() != null && i < c.getRefColumns().size()) {
                                refCol = c.getRefColumns().get(i);
                            }

                            // LOGGING
                            System.out.println("DEBUG DBeaver FK: " + c.getName() + " Col: " + col + " -> " + refTbl
                                    + "." + refCol);

                            rows.add(new Object[] {
                                    refDb, null, refTbl, refCol, // PK Info
                                    db, null, targetTable, col, // FK Info
                                    (long) (i + 1), // SEQ
                                    (long) updateRule, (long) deleteRule, // Rules
                                    c.getName() != null ? c.getName() : "FK_UNKNOWN", // FK_NAME
                                    pkName, // PK_NAME
                                    7L // DEFERRABILITY
                            });
                        }
                    }

                    if (!foundFK) {
                        System.out.println(
                                "❌ DIAGNOSTIC: Constraints exist for " + fullTable
                                        + " but NO FOREIGN KEYS found. List:");
                        for (com.sylo.kylo.core.constraint.Constraint c : consts) {
                            System.out.println("   - " + c.getName() + " [" + c.getType() + "]");
                        }
                    }
                }

                System.out.println("DEBUG: Writing DBeaver FK ResultSet. Rows: " + rows.size());
                rsWriter.writeResultSet(out, rows, schema, seq);

            }
        } catch (

        Exception e) {
            System.err.println("CRITICAL ERROR IN handleDBeaverFKQuery:");
            e.printStackTrace();
            MySQLPacket.writePacket(out,
                    PacketBuilder.buildError(12345, "Internal Error handling FKs: " + e.getMessage()), ++seq);
        }
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

                // Value Parser (Robust Tokenizer)
                String[] valParts = splitIgnoringQuotes(valsStr);
                Object[] row = new Object[schema.getColumnCount()];

                // If cols specified, map them. If not, assume order.
                if (colsStr == null || colsStr.trim().isEmpty()) {
                    for (int i = 0; i < row.length && i < valParts.length; i++) {
                        row[i] = parseValue(valParts[i], schema.getColumn(i).getType());
                    }
                } else {
                    String[] colParts = colsStr.split(","); // Columns usually don't have commas in names
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

        // Handle NULL keyword
        if (clean.equalsIgnoreCase("NULL")) {
            return null;
        }

        if (clean.startsWith("'") && clean.endsWith("'"))
            clean = clean.substring(1, clean.length() - 1);
        else if (clean.startsWith("\"") && clean.endsWith("\""))
            clean = clean.substring(1, clean.length() - 1);

        // Unescape MySQL string escapes injected by PHP PDO emulation
        clean = clean.replace("\\\"", "\"").replace("\\'", "'").replace("\\\\", "\\");

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
        } else if (type instanceof com.sylo.kylo.core.structure.KyloDouble) {
            return Double.parseDouble(clean);
        } else if (type instanceof com.sylo.kylo.core.structure.KyloFloat) {
            return Float.parseFloat(clean);
        } else if (type instanceof com.sylo.kylo.core.structure.KyloDecimal) {
            return new java.math.BigDecimal(clean);
        } else if (type instanceof com.sylo.kylo.core.structure.KyloUuid) {
            return java.util.UUID.fromString(clean);
        } else if (type instanceof com.sylo.kylo.core.structure.KyloDate) {
            return java.time.LocalDate.parse(clean);
        } else if (type instanceof com.sylo.kylo.core.structure.KyloTime) {
            return java.time.LocalTime.parse(clean);
        } else if (type instanceof com.sylo.kylo.core.structure.KyloYear) {
            return java.time.Year.parse(clean);
        } else if (type instanceof com.sylo.kylo.core.structure.KyloBlob) {
            return clean.getBytes(); // Naive string-to-bytes
        } else if (type instanceof com.sylo.kylo.core.structure.KyloJson) {
            return clean; // JSON remains string but validated inside
        } else if (type instanceof com.sylo.kylo.core.structure.KyloEnum) {
            return clean; // Enum remains string but validated inside
        } else if (type instanceof com.sylo.kylo.core.structure.KyloDateTime) {
            try {
                // Try format 'YYYY-MM-DD HH:MM:SS'
                String dt = clean;
                if (dt.length() > 19)
                    dt = dt.substring(0, 19);
                return java.time.LocalDateTime.parse(dt.replace(" ", "T"));
            } catch (Exception e) {
                // Fallback to strict SQL Timestamp parsing
                try {
                    return java.sql.Timestamp.valueOf(clean).toLocalDateTime();
                } catch (Exception e2) {
                    System.out.println("DEBUG: Failed to parse timestamp: " + clean);
                    return null;
                }
            }
        }
        return clean;
    }

    private void parseTableConstraint(String table, String name, String body,
            List<com.sylo.kylo.core.constraint.Constraint> constraints) {
        String upper = body.toUpperCase();
        if (upper.startsWith("PRIMARY KEY")) {
            String colsStr = body.substring(body.indexOf("(") + 1, body.indexOf(")")).replace("`", "");
            List<String> cols = new ArrayList<>();
            for (String c : colsStr.split(","))
                cols.add(c.trim());
            constraints.add(new com.sylo.kylo.core.constraint.Constraint(name,
                    com.sylo.kylo.core.constraint.Constraint.Type.PRIMARY_KEY, table, cols));
        } else if (upper.startsWith("UNIQUE")) {
            String colsStr = body.substring(body.indexOf("(") + 1, body.indexOf(")")).replace("`", "");
            List<String> cols = new ArrayList<>();
            for (String c : colsStr.split(","))
                cols.add(c.trim());
            constraints.add(new com.sylo.kylo.core.constraint.Constraint(name,
                    com.sylo.kylo.core.constraint.Constraint.Type.UNIQUE, table, cols));
        } else if (upper.startsWith("FOREIGN KEY")) {
            // FOREIGN KEY (cols) REFERENCES refTable(refCols)
            String colsStr = body.substring(body.indexOf("(") + 1, body.indexOf(")")).replace("`", "");
            List<String> cols = new ArrayList<>();
            for (String c : colsStr.split(","))
                cols.add(c.trim());

            String rest = body.substring(body.indexOf(")") + 1).trim(); // REFERENCES ...
            if (rest.toUpperCase().startsWith("REFERENCES")) {
                String refPart = rest.substring("REFERENCES".length()).trim();
                String refTable = refPart.substring(0, refPart.indexOf("(")).trim().replace("`", "");
                String refColsStr = refPart.substring(refPart.indexOf("(") + 1, refPart.indexOf(")")).replace("`", "");

                List<String> refCols = new ArrayList<>();
                for (String c : refColsStr.split(","))
                    refCols.add(c.trim());

                com.sylo.kylo.core.constraint.Constraint fk = new com.sylo.kylo.core.constraint.Constraint(name, table,
                        cols, refTable, refCols);
                constraints.add(fk);
            }
        }
    }

    private List<String> splitByCommaRespectingParens(String body) {
        List<String> tokens = new ArrayList<>();
        int parens = 0;
        boolean quotes = false;
        StringBuilder sb = new StringBuilder();
        for (char c : body.toCharArray()) {
            if (c == '\'' || c == '"')
                quotes = !quotes;
            else if (!quotes) {
                if (c == '(')
                    parens++;
                else if (c == ')')
                    parens--;
            }

            if (c == ',' && parens == 0 && !quotes) {
                tokens.add(sb.toString().trim());
                sb.setLength(0);
            } else {
                sb.append(c);
            }
        }
        tokens.add(sb.toString().trim());
        return tokens;
    }

    private com.sylo.kylo.core.structure.KyloType parseType(String typeStr) {
        // DEBUG: Trace what DBeaver is sending
        System.out.println("DEBUG PARSE TYPE: '" + typeStr + "'");

        String t = typeStr.toUpperCase();
        if (t.startsWith("INT") || t.startsWith("INTEGER") || t.startsWith("MEDIUMINT"))
            return new com.sylo.kylo.core.structure.KyloInt();
        if (t.startsWith("BIGINT"))
            return new com.sylo.kylo.core.structure.KyloBigInt();
        if (t.startsWith("VARCHAR") || t.startsWith("CHAR") || t.startsWith("STRING") || t.startsWith("CHARACTER")
                || t.startsWith("VARBINARY")) {
            int size = 255;
            try {
                if (t.contains("(") && t.contains(")")) {
                    size = Integer.parseInt(t.substring(t.indexOf("(") + 1, t.indexOf(")")));
                }
            } catch (Exception e) {
            }
            return new com.sylo.kylo.core.structure.KyloVarchar(size);
        }
        if (t.startsWith("TEXT") || t.startsWith("LONGTEXT") || t.startsWith("MEDIUMTEXT"))
            return new com.sylo.kylo.core.structure.KyloText();
        if (t.startsWith("BOOL") || t.startsWith("TINYINT"))
            return new com.sylo.kylo.core.structure.KyloBoolean();
        if (t.startsWith("TIMESTAMP") || t.startsWith("DATETIME"))
            return new com.sylo.kylo.core.structure.KyloDateTime();
        if (t.startsWith("DATE"))
            return new com.sylo.kylo.core.structure.KyloDate();
        if (t.startsWith("TIME"))
            return new com.sylo.kylo.core.structure.KyloTime();
        if (t.startsWith("YEAR"))
            return new com.sylo.kylo.core.structure.KyloYear();
        if (t.startsWith("DOUBLE") || t.startsWith("REAL"))
            return new com.sylo.kylo.core.structure.KyloDouble();
        if (t.startsWith("FLOAT"))
            return new com.sylo.kylo.core.structure.KyloFloat();
        if (t.startsWith("DECIMAL") || t.startsWith("NUMERIC"))
            return new com.sylo.kylo.core.structure.KyloDecimal();
        if (t.startsWith("BLOB") || t.startsWith("LONGBLOB") || t.startsWith("BINARY"))
            return new com.sylo.kylo.core.structure.KyloBlob();
        if (t.startsWith("JSON"))
            return new com.sylo.kylo.core.structure.KyloJson();
        if (t.startsWith("UUID"))
            return new com.sylo.kylo.core.structure.KyloUuid();
        if (t.startsWith("ENUM")) {
            try {
                if (t.contains("(") && t.contains(")")) {
                    String content = typeStr.substring(typeStr.indexOf("(") + 1, typeStr.lastIndexOf(")"));
                    // Split by comma respecting quotes (simple valid SQL enum check)
                    String[] parts = content.split(",");
                    java.util.Map<String, Integer> mapping = new java.util.HashMap<>();
                    int idx = 1;
                    for (String part : parts) {
                        String val = part.trim().replace("'", "").replace("\"", "");
                        mapping.put(val, idx++);
                    }
                    return new com.sylo.kylo.core.structure.KyloEnum(mapping);
                }
            } catch (Exception e) {
                System.out.println("DEBUG ENUM PARSE ERROR: " + e.getMessage() + " -> Fallback to VARCHAR");
            }
            return new com.sylo.kylo.core.structure.KyloVarchar(255);
        }

        // Fallback
        System.out.println("DEBUG TYPE FALLBACK: " + t + " -> VARCHAR");
        return new com.sylo.kylo.core.structure.KyloVarchar(255);
    }

    // ... inside handleAlterTableAddConstraint ... (Partial replacement for
    // context, assume I need to replace the method or block)
    // Actually, I will targeting specific blocks using replace_file_content with
    // ranges.

    // Let's do this in chunks via multi_replace if possible, or separate calls.
    // The instructions say "Do NOT make multiple parallel calls to replacement
    // tools for the same file."
    // So I must use multi_replace_file_content.

    private void handleCreate(String sql, OutputStream out, byte seq) throws IOException {
        try {
            // Robust CREATE TABLE Parser
            // Pattern: CREATE TABLE [IF NOT EXISTS] table ( body ) [options]
            // Updated to be more permissive with table name capturing (e.g. `db`.`table`)
            String pattern = "(?i)CREATE\\s+TABLE\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?(.+?)\\s*\\((.*)\\)(?:.*)";
            Pattern p = Pattern.compile(pattern, Pattern.DOTALL);
            Matcher m = p.matcher(sql);

            if (m.find()) {
                String fullTableRaw = m.group(1).trim();
                // Clean quotes and handle db.table validation
                String fullTable = fullTableRaw.replace("`", "").replace("'", "").replace("\"", "");
                String body = m.group(2);

                // Handle DB prefix
                String db = session.getCurrentDatabase();
                if (fullTable.contains(".")) {
                    String[] parts = fullTable.split("\\.");
                    if (parts[0].equalsIgnoreCase("mysql"))
                        parts[0] = "kylo_system";
                    db = parts[0];
                    fullTable = db + ":" + parts[1];
                } else {
                    if (db == null)
                        db = "default";
                    if (db.equalsIgnoreCase("mysql"))
                        db = "kylo_system";
                    fullTable = db + ":" + fullTable;
                }

                List<com.sylo.kylo.core.catalog.Column> columns = new ArrayList<>();
                List<com.sylo.kylo.core.constraint.Constraint> constraints = new ArrayList<>();
                List<String> pkCols = new ArrayList<>();

                // Split by comma respecting parens
                List<String> tokens = splitByCommaRespectingParens(body);

                for (String token : tokens) {
                    token = token.trim();
                    if (token.isEmpty())
                        continue;

                    String upper = token.toUpperCase();

                    if (upper.startsWith("CONSTRAINT")) {
                        // CONSTRAINT name TYPE ...
                        Pattern pc = Pattern.compile("(?i)CONSTRAINT\\s+['`]?(\\w+)['`]?\\s+(.+)");
                        Matcher mc = pc.matcher(token);
                        if (mc.find()) {
                            String cName = mc.group(1);
                            String cBody = mc.group(2);
                            parseTableConstraint(fullTable, cName, cBody, constraints);
                        }
                    } else if (upper.startsWith("PRIMARY KEY")) {
                        parseTableConstraint(fullTable, "PK_" + System.currentTimeMillis(), token, constraints);
                    } else if (upper.startsWith("FOREIGN KEY")) {
                        parseTableConstraint(fullTable, "FK_" + System.currentTimeMillis() + "_" + constraints.size(),
                                token, constraints);
                    } else if (upper.startsWith("UNIQUE")) {
                        parseTableConstraint(fullTable, "UQ_" + System.currentTimeMillis() + "_" + constraints.size(),
                                token, constraints);
                    } else {
                        // Column Definition
                        // name type [NOT NULL] [PRIMARY KEY] ...
                        String[] parts = token.split("\\s+");
                        String colName = parts[0].replace("`", "");
                        String typeStr = parts.length > 1 ? parts[1] : "VARCHAR(255)";

                        // Parse Type
                        com.sylo.kylo.core.structure.KyloType type = parseType(typeStr);

                        boolean nullable = true;
                        if (upper.contains("NOT NULL"))
                            nullable = false;
                        else if (upper.contains("PRIMARY KEY"))
                            nullable = false;

                        columns.add(new com.sylo.kylo.core.catalog.Column(colName, type, nullable));

                        // Inline Constraints
                        if (upper.contains("PRIMARY KEY")) {
                            List<String> cCols = new ArrayList<>();
                            cCols.add(colName);
                            constraints.add(new com.sylo.kylo.core.constraint.Constraint("PK_" + colName,
                                    com.sylo.kylo.core.constraint.Constraint.Type.PRIMARY_KEY, fullTable, cCols));
                        }
                        if (upper.contains("UNIQUE")) {
                            List<String> cCols = new ArrayList<>();
                            cCols.add(colName);
                            constraints.add(new com.sylo.kylo.core.constraint.Constraint("UQ_" + colName,
                                    com.sylo.kylo.core.constraint.Constraint.Type.UNIQUE, fullTable, cCols));
                        }
                    }
                }

                // Create Table
                // Create Table
                Schema schema = new Schema(columns);
                Catalog.getInstance().createTable(fullTable, schema);

                // Process Constraints
                for (com.sylo.kylo.core.constraint.Constraint c : constraints) {
                    // Register
                    Catalog.getInstance().getConstraintManager().addConstraint(c);

                    // Creates Indexes for PK/Unique
                    if (c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.PRIMARY_KEY
                            || c.getType() == com.sylo.kylo.core.constraint.Constraint.Type.UNIQUE) {
                        for (String col : c.getColumns()) {
                            try {
                                engine.createIndex(fullTable, col, c.getName());
                            } catch (Exception e) {
                                e.printStackTrace();
                                // Ignore if exists
                            }
                        }
                    }
                }

                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);

            } else {
                // Robust Fallback for CREATE DATABASE / SCHEMA
                String dbPattern = "(?i)CREATE\\s+(?:DATABASE|SCHEMA)\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?['`]?(\\w+)['`]?";
                Pattern pDb = Pattern.compile(dbPattern);
                Matcher mDb = pDb.matcher(sql);

                if (mDb.find()) {
                    String dbName = mDb.group(1);
                    Catalog.getInstance().createDatabase(dbName); // Actually create it!
                    MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
                } else {
                    System.out.println("❌ FAILED CREATE PARSE: " + sql);
                    throw new Exception("Invalid CREATE TABLE syntax for: " + sql);
                }
            }
        } catch (Exception e) {
            e.printStackTrace();
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "Create Error: " + e.getMessage()), ++seq);
        }
    }

    private void handleAlterTable(String sql, OutputStream out, byte seq) throws IOException {
        String upper = sql.toUpperCase();
        try {
            // Map mysql.* tables to kylo_system.*
            sql = sql.replaceAll("(?i)\\bmysql\\.user\\b", "kylo_system.users")
                    .replaceAll("(?i)\\bmysql\\.db\\b", "kylo_system.db_privs")
                    .replaceAll("(?i)\\bmysql\\.tables_priv\\b", "kylo_system.table_privs");

            // Extract Table Name
            String patternTbl = "(?i)ALTER\\s+TABLE\\s+['`]?(?:(\\w+)\\.)?(\\w+)['`]?";
            Matcher mTbl = Pattern.compile(patternTbl).matcher(sql);
            String db = session.getCurrentDatabase();
            String table = "";

            if (mTbl.find()) {
                if (mTbl.group(1) != null)
                    db = mTbl.group(1);
                table = mTbl.group(2);
            } else {
                throw new Exception("Could not parse table name in ALTER TABLE");
            }

            if (db == null)
                db = "Default";
            if (db.equalsIgnoreCase("mysql"))
                db = "kylo_system";
            String fullTable = db + ":" + table;

            System.out.println("ALTER TABLE on " + fullTable + ": " + sql);

            // 1. ADD COLUMN
            // Pattern: ALTER TABLE t ADD [COLUMN] colName colDef
            if (upper.contains("ADD") && !upper.contains("CONSTRAINT")) {
                String addPat = "(?i)ADD\\s+(?:COLUMN\\s+)?['`]?(\\w+)['`]?\\s+(.+)";
                Matcher mAdd = Pattern.compile(addPat).matcher(sql);
                if (mAdd.find()) {
                    String colName = mAdd.group(1);
                    String colDef = mAdd.group(2);
                    // Parse Type/Nullable
                    String[] parts = colDef.split("\\s+");
                    com.sylo.kylo.core.structure.KyloType type = parseType(parts[0]);
                    boolean nullable = !colDef.toUpperCase().contains("NOT NULL");

                    com.sylo.kylo.core.catalog.Column newCol = new com.sylo.kylo.core.catalog.Column(colName, type,
                            nullable);
                    Catalog.getInstance().alterTableAddColumn(fullTable, newCol);
                    MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
                    return;
                }
            }

            // 2. DROP COLUMN
            if (upper.contains("DROP COLUMN")) {
                String dropPat = "(?i)DROP\\s+COLUMN\\s+['`]?(\\w+)['`]?";
                Matcher mDrop = Pattern.compile(dropPat).matcher(sql);
                if (mDrop.find()) {
                    String colName = mDrop.group(1);
                    Catalog.getInstance().alterTableDropColumn(fullTable, colName);
                    MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
                    return;
                }
            }

            // 3. MODIFY/CHANGE COLUMN
            // Split logic because regex for both was ambiguous
            if (upper.contains("MODIFY")) {
                // MODIFY [COLUMN] col_name column_definition
                // Use strict pattern: MODIFY (optional COLUMN) (name) (rest)
                // The name might be quoted.
                String modPat = "(?i)MODIFY\\s+(?:COLUMN\\s+)?['`]?(\\w+)['`]?\\s+(.+)";
                Matcher mMod = Pattern.compile(modPat).matcher(sql);
                if (mMod.find()) {
                    String colName = mMod.group(1);
                    String colDef = mMod.group(2);

                    String[] parts = colDef.split("\\s+");
                    com.sylo.kylo.core.structure.KyloType type = parseType(parts[0]);
                    boolean nullable = !colDef.toUpperCase().contains("NOT NULL");

                    com.sylo.kylo.core.catalog.Column newCol = new com.sylo.kylo.core.catalog.Column(colName, type,
                            nullable);
                    Catalog.getInstance().alterTableModifyColumn(fullTable, newCol);
                    MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
                    return;
                }
            }

            if (upper.contains("CHANGE")) {
                // CHANGE [COLUMN] old_col_name new_col_name column_definition
                String chgPat = "(?i)CHANGE\\s+(?:COLUMN\\s+)?['`]?(\\w+)['`]?\\s+['`]?(\\w+)['`]?\\s+(.+)";
                Matcher mChg = Pattern.compile(chgPat).matcher(sql);
                if (mChg.find()) {
                    String oldColName = mChg.group(1);
                    String newColName = mChg.group(2);
                    String colDef = mChg.group(3);

                    String[] parts = colDef.split("\\s+");
                    com.sylo.kylo.core.structure.KyloType type = parseType(parts[0]);
                    boolean nullable = !colDef.toUpperCase().contains("NOT NULL");

                    // Use new rename-aware method
                    com.sylo.kylo.core.catalog.Column newCol = new com.sylo.kylo.core.catalog.Column(newColName, type,
                            nullable);
                    Catalog.getInstance().alterTableChangeColumn(fullTable, oldColName, newCol);
                    MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
                    return;
                }
            }

            // 4. ADD CONSTRAINT
            if (upper.contains("ADD CONSTRAINT")) {
                // Existing logic for ADD CONSTRAINT ...
                handleAlterTableAddConstraint(sql, out, seq, fullTable, db);
                return;
            }

            // 5. DROP PRIMARY KEY
            if (upper.contains("DROP PRIMARY KEY")) {
                com.sylo.kylo.core.constraint.ConstraintManager.getInstance().removeConstraint(fullTable, "PRIMARY");
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
                return;
            }

            // 6. DROP FOREIGN KEY
            if (upper.contains("DROP FOREIGN KEY")) {
                String dropFkPat = "(?i)DROP\\s+FOREIGN\\s+KEY\\s+['`]?(\\w+)['`]?";
                Matcher mDropFk = Pattern.compile(dropFkPat).matcher(sql);
                if (mDropFk.find()) {
                    String fkName = mDropFk.group(1);
                    com.sylo.kylo.core.constraint.ConstraintManager.getInstance().removeConstraint(fullTable, fkName);
                    MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
                    return;
                }
            }

            // 7. DROP INDEX
            if (upper.contains("DROP INDEX")) {
                String dropIdxPat = "(?i)DROP\\s+INDEX\\s+['`]?(\\w+)['`]?";
                Matcher mDropIdx = Pattern.compile(dropIdxPat).matcher(sql);
                if (mDropIdx.find()) {
                    String idxName = mDropIdx.group(1);
                    // Index Drop Logic (Same as before)
                    try {
                        var indexMgr = com.sylo.kylo.core.index.IndexManager.getInstance();
                        String foundKey = null;
                        for (String key : indexMgr.getIndexNames()) {
                            String registeredName = indexMgr.getIndexName(key);
                            if (registeredName != null && registeredName.equalsIgnoreCase(idxName)) {
                                foundKey = key;
                                break;
                            }
                        }
                        if (foundKey != null) {
                            String[] iParts = foundKey.split("\\.");
                            if (iParts.length >= 2)
                                indexMgr.dropIndex(iParts[0], iParts[1]);
                        }
                        MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
                        return;
                    } catch (Exception e) {
                        throw new Exception("Drop Index Failed: " + e.getMessage());
                    }
                }
            }

            // Fallback: If we reached here, we likely missed something or syntax is
            // unsupported.
            // Do NOT return OK blindly anymore.
            System.err.println("❌ Unparsed ALTER TABLE command: " + sql);
            MySQLPacket.writePacket(out,
                    PacketBuilder.buildError(1064, "Syntax Error or Unsupported ALTER command: " + sql), ++seq);

        } catch (Exception e) {
            e.printStackTrace();
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "Alter Table Error: " + e.getMessage()), ++seq);
        }
    }

    private void handleAlterTableAddConstraint(String sql, OutputStream out, byte seq, String fullTable, String db)
            throws Exception {
        // Moved existing ADD CONSTRAINT logic here
        String upper = sql.toUpperCase();
        String constraintPart = sql.substring(upper.indexOf("ADD CONSTRAINT") + 14).trim();
        String constraintName = constraintPart.split("\\s+")[0].replace("`", "");

        if (upper.contains("FOREIGN KEY")) {
            // ... existing FK logic ...
            String fkPat = "(?i)FOREIGN\\s+KEY\\s*\\((.+?)\\)\\s*REFERENCES\\s+['`]?(?:(\\w+)\\.)?(\\w+)['`]?\\s*\\((.+?)\\)"
                    +
                    "(?:\\s+ON\\s+DELETE\\s+(CASCADE|RESTRICT|SET\\s+NULL|NO\\s+ACTION))?" +
                    "(?:\\s+ON\\s+UPDATE\\s+(CASCADE|RESTRICT|SET\\s+NULL|NO\\s+ACTION))?";
            Matcher mFk = Pattern.compile(fkPat).matcher(constraintPart);
            if (mFk.find()) {
                String childColStr = mFk.group(1).replace("`", "").trim();
                String refDb = mFk.group(2);
                String refTbl = mFk.group(3);
                String refColStr = mFk.group(4).replace("`", "").trim();
                String onDelete = mFk.group(5);
                String onUpdate = mFk.group(6);

                List<String> childCols = new ArrayList<>();
                for (String s : childColStr.split(","))
                    childCols.add(s.trim());
                List<String> refCols = new ArrayList<>();
                for (String s : refColStr.split(","))
                    refCols.add(s.trim());

                String fullRefTable = (refDb != null ? refDb : db) + ":" + refTbl;

                // Register
                com.sylo.kylo.core.index.IndexManager.getInstance().registerForeignKey(
                        constraintName, fullTable, childCols.get(0), fullRefTable, refCols.get(0));

                com.sylo.kylo.core.constraint.Constraint c = new com.sylo.kylo.core.constraint.Constraint(
                        constraintName, fullTable, childCols, fullRefTable, refCols, onDelete, onUpdate);

                Catalog.getInstance().addConstraint(c);
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
            } else {
                throw new Exception("Invalid Foreign Key Syntax");
            }
        } else if (upper.contains("PRIMARY KEY") || upper.contains("UNIQUE")) {
            // ... existing PK/UK logic ...
            com.sylo.kylo.core.constraint.Constraint.Type cType = upper.contains("PRIMARY KEY")
                    ? com.sylo.kylo.core.constraint.Constraint.Type.PRIMARY_KEY
                    : com.sylo.kylo.core.constraint.Constraint.Type.UNIQUE;

            String colPat = "(?i)(?:PRIMARY\\s+KEY|UNIQUE(?:\\s+KEY)?)\\s*\\((.+?)\\)";
            Matcher mPk = Pattern.compile(colPat).matcher(constraintPart);
            if (mPk.find()) {
                String cols = mPk.group(1).replace("`", "").trim();
                List<String> colList = new ArrayList<>();
                for (String s : cols.split(","))
                    colList.add(s.trim());

                com.sylo.kylo.core.constraint.Constraint c = new com.sylo.kylo.core.constraint.Constraint(
                        constraintName, cType, fullTable, colList);
                Catalog.getInstance().addConstraint(c);

                // FIX: Create underlying Index for PK/Unique so FKs can reference it
                for (String col : colList) {
                    try {
                        engine.createIndex(fullTable, col, constraintName);
                        System.out.println("DEBUG: Auto-created index for constraint " + constraintName + " on "
                                + fullTable + "." + col);
                    } catch (Exception e) {
                        // Ignore if exists
                    }
                }

                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
            }
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

        // Dynamic Database List from Catalog
        for (String db : Catalog.getInstance().getDatabases()) {
            rows.add(new Object[] { db });
        }

        // Add System/Meta DBs if not present (DBeaver expect these sometimes)
        // If Catalog already has them, Set dedupes. If not, we add them for
        // compatibility.
        // Actually, let's just rely on Catalog being accurate or add 'mysql' if missing
        // for legacy tools?
        // Catalog initializes with "Default".
        // Let's add "information_schema" mock?
        // For now, just the Catalog DBs + "information_schema" (mock) is good practice.
        if (!Catalog.getInstance().getDatabases().contains("information_schema"))
            rows.add(new Object[] { "information_schema" });
        if (!Catalog.getInstance().getDatabases().contains("mysql"))
            rows.add(new Object[] { "mysql" });
        if (!Catalog.getInstance().getDatabases().contains("performance_schema"))
            rows.add(new Object[] { "performance_schema" });
        if (!Catalog.getInstance().getDatabases().contains("sys"))
            rows.add(new Object[] { "sys" });

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
        cols.add(new com.sylo.kylo.core.catalog.Column("Time", new com.sylo.kylo.core.structure.KyloBigInt(), false));
        cols.add(
                new com.sylo.kylo.core.catalog.Column("State", new com.sylo.kylo.core.structure.KyloVarchar(64), true));
        cols.add(
                new com.sylo.kylo.core.catalog.Column("Info", new com.sylo.kylo.core.structure.KyloVarchar(100), true));

        Schema s = new Schema(cols);
        List<Object[]> rows = new ArrayList<>();

        long idCounter = 1;
        for (KyloBridge bridge : activeSessions.values()) {
            rows.add(new Object[] {
                    idCounter++,
                    bridge.getUser(),
                    bridge.getHost(),
                    bridge.getDb(),
                    "Sleep", // Simplified state
                    bridge.getTime(),
                    "",
                    null
            });
        }

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

    private String[] splitIgnoringQuotes(String str) {
        List<String> tokens = new ArrayList<>();
        boolean inQuotes = false;
        char quoteChar = 0;
        StringBuilder current = new StringBuilder();

        for (int i = 0; i < str.length(); i++) {
            char c = str.charAt(i);
            if (c == '\'' || c == '"') {
                if (inQuotes) {
                    if (c == quoteChar) {
                        // Check for escape? For simple SQL, '' is escape.
                        // If next is quote, it is escape.
                        if (i + 1 < str.length() && str.charAt(i + 1) == quoteChar) {
                            i++; // skip next quote, let fallthrough append this one (unescape '' -> ')
                        } else {
                            inQuotes = false;
                        }
                    }
                    // Else: Quote inside other quote (e.g. " ' "). Do nothing, let fallthrough
                    // append.
                } else {
                    inQuotes = true;
                    quoteChar = c;
                }
            } else if (c == ',' && !inQuotes) {
                tokens.add(current.toString().trim());
                current.setLength(0);
                continue;
            }

            current.append(c);
        }
        tokens.add(current.toString().trim()); // Add last token
        return tokens.toArray(new String[0]);
    }

    private String[] splitByAndIgnoringQuotes(String str) {
        List<String> tokens = new ArrayList<>();
        boolean inQuotes = false;
        char quoteChar = 0;
        StringBuilder current = new StringBuilder();

        for (int i = 0; i < str.length(); i++) {
            char c = str.charAt(i);
            if (c == '\'' || c == '"') {
                if (inQuotes) {
                    if (c == quoteChar) {
                        if (i + 1 < str.length() && str.charAt(i + 1) == quoteChar) {
                            current.append(c);
                            i++;
                        } else {
                            inQuotes = false;
                        }
                    } else {
                        current.append(c);
                    }
                } else {
                    inQuotes = true;
                    quoteChar = c;
                }
            }

            if (!inQuotes) {
                if (isAndAt(str, i)) {
                    tokens.add(current.toString().trim());
                    current.setLength(0);
                    i += 2; // Skip 'A', 'N' ('D' skipped by loop increment) -> actually i+=2 lands on 'D',
                            // loop i++ lands on next
                    // Wait, logic:
                    // i is at 'A'.
                    // i+=2 sets i to 'D'.
                    // loop continues, does i++.
                    // next iteration i is at char AFTER 'D'.
                    // Correct.
                    continue;
                }
            }
            current.append(c);
        }
        tokens.add(current.toString().trim());
        return tokens.toArray(new String[0]);
    }

    private boolean isAndAt(String str, int i) {
        if (i + 3 > str.length())
            return false;
        String sub = str.substring(i, i + 3);
        if (!sub.equalsIgnoreCase("AND"))
            return false;

        // Check Previous
        boolean startOk = (i == 0);
        if (!startOk) {
            char prev = str.charAt(i - 1);
            if (Character.isWhitespace(prev) || prev == ')' || prev == '`' || prev == '\'' || prev == '"')
                startOk = true;
        }

        // Check Next
        boolean endOk = (i + 3 >= str.length());
        if (!endOk) {
            char next = str.charAt(i + 3);
            if (Character.isWhitespace(next) || next == '(' || next == '`' || next == '\'' || next == '"')
                endOk = true;
        }

        return startOk && endOk;
    }

    // --- Advanced SQL Handlers ---

    private void handleCreateRoutine(String sql, OutputStream out, byte seq) throws IOException {
        try {
            // Pattern: CREATE [DEFINER=...] PROCEDURE|FUNCTION [IF NOT EXISTS] name
            // (params) [LANGUAGE ...] body
            System.out.println("Processing Routine DDL: " + sql.substring(0, Math.min(sql.length(), 100)));

            boolean isProc = sql.toUpperCase().contains("PROCEDURE");
            Routine.RoutineType type = isProc ? Routine.RoutineType.PROCEDURE : Routine.RoutineType.FUNCTION;

            // Extract Name
            String namePatternStr = "(?i)(?:PROCEDURE|FUNCTION)\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?([`'\\w\\.]+)";
            Pattern pName = Pattern.compile(namePatternStr);
            Matcher mName = pName.matcher(sql);
            if (!mName.find()) {
                throw new Exception("Could not parse Routine Name");
            }
            String fullName = mName.group(1).replace("`", "").replace("'", "");
            String db = session.getCurrentDatabase();
            String name = fullName;
            if (fullName.contains(".")) {
                String[] parts = fullName.split("\\.");
                db = parts[0];
                name = parts[1];
            }

            // Extract Parameters: name(p1, p2)
            // Regex to find content inside first parenthesis after name?
            // Name might be quoted.
            // Pattern: name\s*\((.*?)\)
            List<String> params = new ArrayList<>();
            Pattern pParams = Pattern.compile(
                    "(?i)(?:PROCEDURE|FUNCTION)\\s+(?:IF\\s+NOT\\s+EXISTS\\s+)?(?:[`'\\w\\.]+\\.)?[`'\\w]+\\s*\\((.*?)\\)",
                    Pattern.DOTALL);
            Matcher mParams = pParams.matcher(sql);
            if (mParams.find()) {
                String paramStr = mParams.group(1).trim();
                if (!paramStr.isEmpty()) {
                    // Split by comma, handling potential complexity (though we assume simple names
                    // for now)
                    List<String> pTokens = splitByCommaRespectingParens(paramStr);
                    for (String pt : pTokens) {
                        // Format: [IN/OUT] name type
                        // e.g. "name VARCHAR(255)"
                        // We just want the name for binding.
                        // Simple heuristic: First token is name, unless IN/OUT/INOUT?
                        String[] partsParam = pt.trim().split("\\s+");
                        String paramName = partsParam[0];
                        if (paramName.equalsIgnoreCase("IN") || paramName.equalsIgnoreCase("OUT")
                                || paramName.equalsIgnoreCase("INOUT")) {
                            if (partsParam.length > 1)
                                paramName = partsParam[1];
                        }
                        params.add(paramName.replace("`", ""));
                    }
                }
            }

            // Extract Language (Default SQL)
            Routine.Language lang = Routine.Language.SQL;
            Pattern pLang = Pattern.compile("LANGUAGE\\s+(\\w+)", Pattern.CASE_INSENSITIVE);
            Matcher mLang = pLang.matcher(sql);
            if (mLang.find()) {
                String l = mLang.group(1).toUpperCase();
                if (l.equals("LUA"))
                    lang = Routine.Language.LUA;
                else if (l.equals("JS") || l.equals("JAVASCRIPT"))
                    lang = Routine.Language.JS;
            } else if (sql.startsWith("-- language:lua")) {
                // Heuristic for comments
                lang = Routine.Language.LUA;
            }

            // Extract Body
            String body = sql;
            // Robust AS detection using Regex
            Pattern pBody = Pattern.compile("(?i)\\bAS\\b(.*)", Pattern.DOTALL);
            Matcher mBody = pBody.matcher(sql);
            if (mBody.find()) {
                body = mBody.group(1).trim();
                // Strip trailing delimiter if present
                if (body.endsWith("$$"))
                    body = body.substring(0, body.length() - 2).trim();
                else if (body.endsWith("//"))
                    body = body.substring(0, body.length() - 2).trim();
                else if (body.endsWith(";"))
                    body = body.substring(0, body.length() - 1).trim();
            } else {
                // Fallback to simple split if regex fails (unlikely for valid syntax)
                // But maybe it uses BEGIN?
                Pattern pBegin = Pattern.compile("(?i)\\bBEGIN\\b(.*)", Pattern.DOTALL);
                Matcher mBegin = pBegin.matcher(sql);
                if (mBegin.find()) {
                    body = mBegin.group(1).trim();
                    // If standard SQL BEGIN/END, we might want to keep BEGIN?
                    // But for Polyglot, BEGIN is usually start of block.
                    // Let's assume body is what follows.
                }
            }

            Routine r = new Routine(db, name, type, lang, body);
            r.setParams(params);
            Catalog.getInstance().getRoutineManager().addRoutine(r);

            // Also register in kylo_system:proc for SQL queries
            // Cols: db, name, type, language, param_list, returns, body, is_deterministic,
            // created, modified
            String paramListStr = String.join(",", params);
            Object[] sysTuple = new Object[] {
                    db, name, type.toString(), lang.toString(), paramListStr, "", body, false,
                    LocalDateTime.now().toString(),
                    LocalDateTime.now().toString()
            };
            try {
                engine.insertTuple("kylo_system:proc", sysTuple);
            } catch (Exception ex) {
                System.err.println("Warning: Failed to insert into kylo_system:proc: " + ex.getMessage());
            }

            MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
        } catch (Exception e) {
            e.printStackTrace();
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "Routine Syntax Error: " + e.getMessage()),
                    ++seq);
        }
    }

    private void handleDropRoutine(String sql, OutputStream out, byte seq) throws IOException {
        String type = sql.toUpperCase().contains("PROCEDURE") ? "PROCEDURE" : "FUNCTION";
        // DROP PROCEDURE [IF EXISTS] name
        String namePart = sql.replaceAll("(?i)DROP\\s+" + type + "\\s+(?:IF\\s+EXISTS\\s+)?", "").trim()
                .replace(";", "").replace("`", "");
        String db = session.getCurrentDatabase();
        String name = namePart;
        if (namePart.contains(".")) {
            String[] parts = namePart.split("\\.");
            db = parts[0];
            name = parts[1];
        }

        Catalog.getInstance().getRoutineManager().dropRoutine(db, name);
        // Also remove from kylo_system:proc? Complex without delete support.
        // For now, Managers are source of truth.

        MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
    }

    private void handleCreateView(String sql, OutputStream out, byte seq) throws IOException {
        try {
            // CREATE [OR REPLACE] [ALGORITHM=...] VIEW [db.]name ... AS SELECT ...
            Pattern p = Pattern.compile("(?i)VIEW\\s+([`'\\w\\.]+)\\s+AS\\s+(.*)", Pattern.DOTALL);
            Matcher m = p.matcher(sql);
            if (m.find()) {
                String fullName = m.group(1).replace("`", "");
                String definition = m.group(2).trim(); // The SELECT part

                String db = session.getCurrentDatabase();
                String name = fullName;
                if (fullName.contains(".")) {
                    String[] parts = fullName.split("\\.");
                    db = parts[0];
                    name = parts[1];
                }

                View v = new View(db, name, definition);
                Catalog.getInstance().getViewManager().addView(v);

                // Register in kylo_system:views
                // Cols: table_schema, table_name, view_definition, is_updatable
                Object[] sysTuple = new Object[] {
                        db, name, definition, false
                };
                try {
                    engine.insertTuple("kylo_system:views", sysTuple);
                } catch (Exception ex) {
                    System.err.println("Warning: Failed to insert into kylo_system:views");
                }

                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
            } else {
                throw new Exception("Invalid CREATE VIEW syntax");
            }
        } catch (Exception e) {
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "View Error: " + e.getMessage()), ++seq);
        }
    }

    private void handleDropView(String sql, OutputStream out, byte seq) throws IOException {
        String namePart = sql.replaceAll("(?i)DROP\\s+VIEW\\s+(?:IF\\s+EXISTS\\s+)?", "").trim().replace(";", "")
                .replace("`", "");
        // Handle db.view
        String db = session.getCurrentDatabase();
        String name = namePart;
        if (namePart.contains(".")) {
            String[] parts = namePart.split("\\.");
            db = parts[0];
            name = parts[1];
        }
        Catalog.getInstance().getViewManager().dropView(db, name);
        MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
    }

    private void handleCreateTrigger(String sql, OutputStream out, byte seq) throws IOException {
        try {
            // CREATE TRIGGER name BEFORE/AFTER INSERT/UPDATE/DELETE ON table FOR EACH ROW
            // body
            // DBeaver may send incomplete syntax (no body)
            String pattern = "(?i)TRIGGER\\s+([`'\\w\\.]+)\\s+(BEFORE|AFTER)\\s+(INSERT|UPDATE|DELETE)\\s+ON\\s+([`'\\w\\.]+)(?:\\s+FOR\\s+EACH\\s+ROW)?\\s*(.*)";
            Pattern p = Pattern.compile(pattern, Pattern.DOTALL);
            Matcher m = p.matcher(sql);

            if (m.find()) {
                String fullName = m.group(1).replace("`", "").replace("'", "");
                String timingStr = m.group(2).toUpperCase();
                String eventStr = m.group(3).toUpperCase();
                String tableStr = m.group(4).replace("`", "").replace("'", "");
                String body = m.group(5) != null ? m.group(5).trim() : "";

                // If body is empty, use a placeholder
                if (body.isEmpty()) {
                    body = "-- Empty trigger body";
                }

                String db = session.getCurrentDatabase();
                String name = fullName;
                if (fullName.contains(".")) {
                    String[] parts = fullName.split("\\.");
                    db = parts[0];
                    name = parts[1];
                }

                Trigger.Timing timing = Trigger.Timing.valueOf(timingStr);
                Trigger.Event event = Trigger.Event.valueOf(eventStr);

                String targetTable = tableStr;
                if (!tableStr.contains(".")) {
                    targetTable = db + ":" + tableStr;
                } else {
                    targetTable = tableStr.replace(".", ":");
                }

                Trigger t = new Trigger(db, name, targetTable, timing, event, body);
                Catalog.getInstance().getTriggerManager().addTrigger(t);

                // Register in kylo_system:triggers
                Object[] sysTuple = new Object[] {
                        db, name, targetTable, timing.toString(), event.toString(), body
                };
                try {
                    engine.insertTuple("kylo_system:triggers", sysTuple);
                } catch (Exception ex) {
                    System.err.println("Warning: Failed to insert into kylo_system:triggers");
                }

                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
            } else {
                throw new Exception("Invalid CREATE TRIGGER syntax");
            }
        } catch (Exception e) {
            e.printStackTrace();
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "Trigger Error: " + e.getMessage()), ++seq);
        }
    }

    private void handleDropTrigger(String sql, OutputStream out, byte seq) throws IOException {
        String namePart = sql.replaceAll("(?i)DROP\\s+TRIGGER\\s+(?:IF\\s+EXISTS\\s+)?", "").trim().replace(";", "")
                .replace("`", "");
        String db = session.getCurrentDatabase();
        String name = namePart;
        if (namePart.contains(".")) {
            String[] parts = namePart.split("\\.");
            db = parts[0];
            name = parts[1];
        }
        Catalog.getInstance().getTriggerManager().dropTrigger(db, name);
        MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
    }

    private void handleCreateEvent(String sql, OutputStream out, byte seq) throws IOException {
        try {
            // DBeaver sends: CREATE EVENT name ON SCHEDULE AT CURRENT_TIMESTAMP DISABLE ON
            // SLAVE DO ...
            // Standard: CREATE EVENT name ON SCHEDULE EVERY 10 SECOND DO ...

            // Strip DBeaver-specific clauses
            String cleaned = sql.replaceAll("(?i)\\bDISABLE\\s+ON\\s+SLAVE\\b", "")
                    .replaceAll("(?i)\\bENABLE\\s+ON\\s+SLAVE\\b", "")
                    .replaceAll("(?i)\\bON\\s+COMPLETION\\s+(?:NOT\\s+)?PRESERVE\\b", "")
                    .trim();

            // Try EVERY pattern first
            String patternEvery = "(?i)EVENT\\s+([`'\\w\\.]+)\\s+ON\\s+SCHEDULE\\s+EVERY\\s+(\\d+)\\s+(\\w+).*?DO\\s+(.*)";
            Pattern pEvery = Pattern.compile(patternEvery, Pattern.DOTALL);
            Matcher mEvery = pEvery.matcher(cleaned);

            // Try AT pattern (DBeaver)
            String patternAt = "(?i)EVENT\\s+([`'\\w\\.]+)\\s+ON\\s+SCHEDULE\\s+AT\\s+(.+?)\\s+DO\\s+(.*)";
            Pattern pAt = Pattern.compile(patternAt, Pattern.DOTALL);
            Matcher mAt = pAt.matcher(cleaned);

            String db = session.getCurrentDatabase();
            String name;
            String body;
            Long intervalValue = null;
            String intervalField = null;

            if (mEvery.find()) {
                // EVERY syntax
                String fullName = mEvery.group(1).replace("`", "").replace("'", "");
                String valStr = mEvery.group(2);
                intervalField = mEvery.group(3);
                body = mEvery.group(4).trim();

                if (fullName.contains(".")) {
                    String[] parts = fullName.split("\\.");
                    db = parts[0];
                    name = parts[1];
                } else {
                    name = fullName;
                }

                intervalValue = Long.parseLong(valStr);

            } else if (mAt.find()) {
                // AT syntax (DBeaver) - treat as one-time event
                String fullName = mAt.group(1).replace("`", "").replace("'", "");
                body = mAt.group(3).trim();

                if (fullName.contains(".")) {
                    String[] parts = fullName.split("\\.");
                    db = parts[0];
                    name = parts[1];
                } else {
                    name = fullName;
                }

                // One-time event: no interval
                intervalValue = 0L;
                intervalField = "ONE_TIME";

            } else {
                throw new Exception("Invalid CREATE EVENT syntax");
            }

            Event e = new Event(db, name, body);
            if (intervalValue != null) {
                e.setIntervalValue(intervalValue);
                e.setIntervalField(intervalField);
            }

            Catalog.getInstance().getEventManager().addEvent(e);

            // Register in kylo_system:events
            Object[] sysTuple = new Object[] {
                    db, name, "ENABLED", null, intervalValue, intervalField
            };
            try {
                engine.insertTuple("kylo_system:events", sysTuple);
            } catch (Exception ex) {
                System.err.println("Warning: Failed to insert into kylo_system:events");
            }

            MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
        } catch (Exception e) {
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "Event Error: " + e.getMessage()), ++seq);
        }
    }

    private void handleDropEvent(String sql, OutputStream out, byte seq) throws IOException {
        String namePart = sql.replaceAll("(?i)DROP\\s+EVENT\\s+(?:IF\\s+EXISTS\\s+)?", "").trim().replace(";", "")
                .replace("`", "");
        String db = session.getCurrentDatabase();
        String name = namePart;
        if (namePart.contains(".")) {
            String[] parts = namePart.split("\\.");
            db = parts[0];
            name = parts[1];
        }
        Catalog.getInstance().getEventManager().dropEvent(db, name);
        MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
    }

    private void handleInformationSchemaRoutines(OutputStream out, byte seq, String sql) throws IOException {
        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("SPECIFIC_NAME",
                new com.sylo.kylo.core.structure.KyloVarchar(64), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("ROUTINE_CATALOG",
                new com.sylo.kylo.core.structure.KyloVarchar(64), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("ROUTINE_SCHEMA",
                new com.sylo.kylo.core.structure.KyloVarchar(64), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("ROUTINE_NAME", new com.sylo.kylo.core.structure.KyloVarchar(64),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("ROUTINE_TYPE", new com.sylo.kylo.core.structure.KyloVarchar(20),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("DATA_TYPE", new com.sylo.kylo.core.structure.KyloVarchar(64),
                true));
        cols.add(new com.sylo.kylo.core.catalog.Column("DTD_IDENTIFIER",
                new com.sylo.kylo.core.structure.KyloVarchar(64), true));
        cols.add(new com.sylo.kylo.core.catalog.Column("ROUTINE_BODY", new com.sylo.kylo.core.structure.KyloVarchar(10),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("ROUTINE_DEFINITION",
                new com.sylo.kylo.core.structure.KyloVarchar(65535), true));
        cols.add(new com.sylo.kylo.core.catalog.Column("EXTERNAL_NAME",
                new com.sylo.kylo.core.structure.KyloVarchar(64), true));
        cols.add(new com.sylo.kylo.core.catalog.Column("EXTERNAL_LANGUAGE",
                new com.sylo.kylo.core.structure.KyloVarchar(64), true));
        cols.add(new com.sylo.kylo.core.catalog.Column("PARAMETER_STYLE",
                new com.sylo.kylo.core.structure.KyloVarchar(10), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("IS_DETERMINISTIC",
                new com.sylo.kylo.core.structure.KyloVarchar(3), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("SQL_DATA_ACCESS",
                new com.sylo.kylo.core.structure.KyloVarchar(64), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("SQL_PATH", new com.sylo.kylo.core.structure.KyloVarchar(64),
                true));
        cols.add(new com.sylo.kylo.core.catalog.Column("SECURITY_TYPE",
                new com.sylo.kylo.core.structure.KyloVarchar(10), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("CREATED", new com.sylo.kylo.core.structure.KyloVarchar(30),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("LAST_ALTERED", new com.sylo.kylo.core.structure.KyloVarchar(30),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("SQL_MODE", new com.sylo.kylo.core.structure.KyloVarchar(255),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("ROUTINE_COMMENT",
                new com.sylo.kylo.core.structure.KyloVarchar(64), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("DEFINER", new com.sylo.kylo.core.structure.KyloVarchar(255),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("CHARACTER_SET_CLIENT",
                new com.sylo.kylo.core.structure.KyloVarchar(32), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("COLLATION_CONNECTION",
                new com.sylo.kylo.core.structure.KyloVarchar(32), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("DATABASE_COLLATION",
                new com.sylo.kylo.core.structure.KyloVarchar(32), false));

        Schema s = new Schema(cols);
        List<Object[]> rows = new ArrayList<>();

        // Filtering Logic
        String targetSchema = null;
        java.util.regex.Pattern pSch = java.util.regex.Pattern
                .compile("(?i)ROUTINE_SCHEMA\\s*=\\s*['\"]([^'\"]+)['\"]");
        java.util.regex.Matcher mSch = pSch.matcher(sql);
        if (mSch.find()) {
            targetSchema = mSch.group(1);
        }

        String currentDb = session.getCurrentDatabase();

        for (Routine r : Catalog.getInstance().getRoutineManager().getAllRoutines().values()) {
            // Filter by Schema
            String rSchema = r.getDb();

            if (targetSchema != null && !rSchema.equalsIgnoreCase(targetSchema)) {
                continue;
            }
            if (targetSchema == null && currentDb != null && !rSchema.equalsIgnoreCase(currentDb)) {
                // If no specific filter, default to current DB to prevent ghosting
                continue;
            }

            rows.add(new Object[] {
                    r.getName(), "def", r.getDb(), r.getName(), r.getType().toString(),
                    "", "", "SQL", r.getBody(), null, r.getLanguage().toString(), "SQL",
                    (r.isDeterministic() ? "YES" : "NO"), "CONTAINS SQL", null, "DEFINER",
                    r.getCreated().toString(), r.getModified().toString(), "", "", r.getDefiner(),
                    "utf8mb4", "utf8mb4_0900_ai_ci", "utf8mb4_0900_ai_ci"
            });
        }

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleInformationSchemaTriggers(OutputStream out, byte seq, String sql) throws IOException {
        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("TRIGGER_CATALOG",
                new com.sylo.kylo.core.structure.KyloVarchar(64), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("TRIGGER_SCHEMA",
                new com.sylo.kylo.core.structure.KyloVarchar(64), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("TRIGGER_NAME", new com.sylo.kylo.core.structure.KyloVarchar(64),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("EVENT_MANIPULATION",
                new com.sylo.kylo.core.structure.KyloVarchar(10), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("EVENT_OBJECT_CATALOG",
                new com.sylo.kylo.core.structure.KyloVarchar(64), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("EVENT_OBJECT_SCHEMA",
                new com.sylo.kylo.core.structure.KyloVarchar(64), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("EVENT_OBJECT_TABLE",
                new com.sylo.kylo.core.structure.KyloVarchar(64), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("ACTION_ORDER", new com.sylo.kylo.core.structure.KyloBigInt(),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("ACTION_CONDITION",
                new com.sylo.kylo.core.structure.KyloVarchar(65535), true));
        cols.add(new com.sylo.kylo.core.catalog.Column("ACTION_STATEMENT",
                new com.sylo.kylo.core.structure.KyloVarchar(65535), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("ACTION_ORIENTATION",
                new com.sylo.kylo.core.structure.KyloVarchar(10), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("ACTION_TIMING",
                new com.sylo.kylo.core.structure.KyloVarchar(10), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("ACTION_REFERENCE_OLD_TABLE",
                new com.sylo.kylo.core.structure.KyloVarchar(64), true));
        cols.add(new com.sylo.kylo.core.catalog.Column("ACTION_REFERENCE_NEW_TABLE",
                new com.sylo.kylo.core.structure.KyloVarchar(64), true));
        cols.add(new com.sylo.kylo.core.catalog.Column("ACTION_REFERENCE_OLD_ROW",
                new com.sylo.kylo.core.structure.KyloVarchar(3), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("ACTION_REFERENCE_NEW_ROW",
                new com.sylo.kylo.core.structure.KyloVarchar(3), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("CREATED", new com.sylo.kylo.core.structure.KyloVarchar(30),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("SQL_MODE", new com.sylo.kylo.core.structure.KyloVarchar(255),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("DEFINER", new com.sylo.kylo.core.structure.KyloVarchar(255),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("CHARACTER_SET_CLIENT",
                new com.sylo.kylo.core.structure.KyloVarchar(32), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("COLLATION_CONNECTION",
                new com.sylo.kylo.core.structure.KyloVarchar(32), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("DATABASE_COLLATION",
                new com.sylo.kylo.core.structure.KyloVarchar(32), false));

        Schema s = new Schema(cols);
        List<Object[]> rows = new ArrayList<>();

        // Filtering Logic
        String targetSchema = null;
        java.util.regex.Pattern pSch = java.util.regex.Pattern
                .compile("(?i)TRIGGER_SCHEMA\\s*=\\s*['\"]([^'\"]+)['\"]");
        java.util.regex.Matcher mSch = pSch.matcher(sql);
        if (mSch.find()) {
            targetSchema = mSch.group(1);
        } else {
            // Try EVENT_OBJECT_SCHEMA as fallback
            java.util.regex.Pattern pSch2 = java.util.regex.Pattern
                    .compile("(?i)EVENT_OBJECT_SCHEMA\\s*=\\s*['\"]([^'\"]+)['\"]");
            java.util.regex.Matcher mSch2 = pSch2.matcher(sql);
            if (mSch2.find()) {
                targetSchema = mSch2.group(1);
            }
        }

        String currentDb = session.getCurrentDatabase();

        for (Trigger t : Catalog.getInstance().getTriggerManager().getAllTriggers().values()) {
            String tSchema = t.getTriggerSchema();

            if (targetSchema != null && !tSchema.equalsIgnoreCase(targetSchema)) {
                // System.out.println("DEBUG TRIG: Skipped " + t.getName() + " (Schema mismatch:
                // " + tSchema + " != " + targetSchema + ")");
                continue;
            }
            if (targetSchema == null && currentDb != null && !tSchema.equalsIgnoreCase(currentDb)) {
                System.out.println(
                        "DEBUG TRIG: Skipped " + t.getName() + " (Ctx Mismatch: " + tSchema + " != " + currentDb + ")");
                continue;
            }

            System.out.println("DEBUG TRIG: Include " + t.getName() + " Schema=" + tSchema + " Target=" + targetSchema
                    + " Curr=" + currentDb);

            String tTable = t.getEventTable();
            // tSchema is already got
            String tName = t.getName(); // assuming pure name stored map-side or managed by wrapper
            // t.getName() is just name
            String tObjSchema = tSchema;
            String tObjTable = tTable;
            if (tTable.contains(":")) {
                String[] parts = tTable.split(":");
                tObjSchema = parts[0];
                tObjTable = parts[1];
            }

            rows.add(new Object[] {
                    "def", tSchema, tName, t.getEvent().toString(),
                    "def", tObjSchema, tObjTable, 1L, null, t.getStatement(),
                    "ROW", t.getTiming().toString(), null, null, "OLD", "NEW",
                    t.getCreated().toString(), "", t.getDefiner(),
                    "utf8mb4", "utf8mb4_0900_ai_ci", "utf8mb4_0900_ai_ci"
            });
        }

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleInformationSchemaEvents(OutputStream out, byte seq, String sql) throws IOException {
        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("EVENT_CATALOG",
                new com.sylo.kylo.core.structure.KyloVarchar(64), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("EVENT_SCHEMA", new com.sylo.kylo.core.structure.KyloVarchar(64),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("EVENT_NAME", new com.sylo.kylo.core.structure.KyloVarchar(64),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("DEFINER", new com.sylo.kylo.core.structure.KyloVarchar(255),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("TIME_ZONE", new com.sylo.kylo.core.structure.KyloVarchar(64),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("EVENT_BODY", new com.sylo.kylo.core.structure.KyloVarchar(10),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("EVENT_DEFINITION",
                new com.sylo.kylo.core.structure.KyloVarchar(65535), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("EVENT_TYPE", new com.sylo.kylo.core.structure.KyloVarchar(10),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("EXECUTE_AT", new com.sylo.kylo.core.structure.KyloVarchar(30),
                true));
        cols.add(new com.sylo.kylo.core.catalog.Column("INTERVAL_VALUE",
                new com.sylo.kylo.core.structure.KyloVarchar(20), true));
        cols.add(new com.sylo.kylo.core.catalog.Column("INTERVAL_FIELD",
                new com.sylo.kylo.core.structure.KyloVarchar(20), true));
        cols.add(new com.sylo.kylo.core.catalog.Column("SQL_MODE", new com.sylo.kylo.core.structure.KyloVarchar(255),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("STARTS", new com.sylo.kylo.core.structure.KyloVarchar(30),
                true));
        cols.add(new com.sylo.kylo.core.catalog.Column("ENDS", new com.sylo.kylo.core.structure.KyloVarchar(30), true));
        cols.add(new com.sylo.kylo.core.catalog.Column("STATUS", new com.sylo.kylo.core.structure.KyloVarchar(20),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("ON_COMPLETION",
                new com.sylo.kylo.core.structure.KyloVarchar(20), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("CREATED", new com.sylo.kylo.core.structure.KyloVarchar(30),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("LAST_ALTERED", new com.sylo.kylo.core.structure.KyloVarchar(30),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("LAST_EXECUTED",
                new com.sylo.kylo.core.structure.KyloVarchar(30), true));
        cols.add(new com.sylo.kylo.core.catalog.Column("EVENT_COMMENT",
                new com.sylo.kylo.core.structure.KyloVarchar(64), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("ORIGINATOR", new com.sylo.kylo.core.structure.KyloBigInt(),
                false));
        cols.add(new com.sylo.kylo.core.catalog.Column("CHARACTER_SET_CLIENT",
                new com.sylo.kylo.core.structure.KyloVarchar(32), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("COLLATION_CONNECTION",
                new com.sylo.kylo.core.structure.KyloVarchar(32), false));
        cols.add(new com.sylo.kylo.core.catalog.Column("DATABASE_COLLATION",
                new com.sylo.kylo.core.structure.KyloVarchar(32), false));

        Schema s = new Schema(cols);
        List<Object[]> rows = new ArrayList<>();

        // Filtering Logic
        String targetSchema = null;
        java.util.regex.Pattern pSch = java.util.regex.Pattern.compile("(?i)EVENT_SCHEMA\\s*=\\s*['\"]([^'\"]+)['\"]");
        java.util.regex.Matcher mSch = pSch.matcher(sql);
        if (mSch.find()) {
            targetSchema = mSch.group(1);
        }
        String currentDb = session.getCurrentDatabase();

        for (Event e : Catalog.getInstance().getEventManager().getAllEvents().values()) {
            if (targetSchema != null && !e.getSchema().equalsIgnoreCase(targetSchema))
                continue;
            if (targetSchema == null && currentDb != null && !e.getSchema().equalsIgnoreCase(currentDb))
                continue;

            rows.add(new Object[] {
                    "def", e.getSchema(), e.getName(), "root@localhost", "SYSTEM",
                    "SQL", e.getBody(), "RECURRING", null,
                    String.valueOf(e.getIntervalValue()), e.getIntervalField(),
                    "", null, null, "ENABLED", "NOT PRESERVE",
                    LocalDateTime.now().toString(), LocalDateTime.now().toString(), null,
                    "", 1L, "utf8mb4", "utf8mb4_0900_ai_ci", "utf8mb4_0900_ai_ci"
            });
        }

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleCall(String sql, OutputStream out, byte seq) throws IOException {
        try {
            // CALL procName(args)
            String clean = sql.trim().substring(4).trim(); // Remove CALL
            int parenOpen = clean.indexOf('(');
            int parenClose = clean.lastIndexOf(')');

            String procName;
            String argsStr = "";
            if (parenOpen > 0 && parenClose > parenOpen) {
                procName = clean.substring(0, parenOpen).trim();
                argsStr = clean.substring(parenOpen + 1, parenClose).trim();
            } else {
                procName = clean.trim();
            }

            String db = session.getCurrentDatabase();
            String name = procName.replace("`", "").replace("'", "");
            if (procName.contains(".")) {
                String[] parts = procName.split("\\.");
                db = parts[0].replace("`", "").replace("'", "");
                if (parts.length > 1)
                    name = parts[1].replace("`", "").replace("'", "");
            }

            Routine r = Catalog.getInstance().getRoutineManager().getRoutine(db, name);
            if (r == null) {
                throw new Exception("Procedure " + db + "." + name + " does not exist");
            }

            // Parse arguments
            Object[] args;
            if (argsStr.isEmpty()) {
                args = new Object[0];
            } else {
                String[] parts = splitIgnoringQuotes(argsStr);
                args = new Object[parts.length];
                for (int i = 0; i < parts.length; i++) {
                    String p = parts[i].trim();
                    if (p.startsWith("'") && p.endsWith("'")) {
                        args[i] = p.substring(1, p.length() - 1);
                    } else if (p.equalsIgnoreCase("NULL")) {
                        args[i] = null;
                    } else {
                        try {
                            if (p.contains("."))
                                args[i] = Double.parseDouble(p);
                            else
                                args[i] = Long.parseLong(p);
                        } catch (NumberFormatException e) {
                            args[i] = p; // fallback string
                        }
                    }
                }
            }

            PolyglotScriptExecutor.execute(r, session, engine, args);
            MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);

        } catch (Exception e) {
            e.printStackTrace();
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1305, "Procedure Execution Error: " + e.getMessage()),
                    ++seq);
        }
    }

    private void handleDropDatabase(String sql, OutputStream out, byte seq) throws IOException {
        Pattern p = Pattern.compile("(?i)DROP\\s+(?:DATABASE|SCHEMA)\\s+(?:IF\\s+EXISTS\\s+)?['`]?(\\w+)['`]?.*");
        Matcher m = p.matcher(sql);
        if (m.find()) {
            String dbName = m.group(1);
            java.util.List<String> droppedTables = Catalog.getInstance().dropDatabase(dbName);
            for (String t : droppedTables) {
                engine.dropTable(t);
            }
            MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
        } else {
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "Invalid DROP DATABASE syntax"), ++seq);
        }
    }
}
