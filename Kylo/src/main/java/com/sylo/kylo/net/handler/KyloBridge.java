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
import com.sylo.kylo.core.security.SecurityUtils;

public class KyloBridge {
    private final ExecutionEngine engine;
    private final ResultSetWriter rsWriter;

    private final com.sylo.kylo.core.security.SecurityInterceptor interceptor;
    private String currentDb = "default";

    public KyloBridge(ExecutionEngine engine) {
        this.engine = engine;
        this.rsWriter = new ResultSetWriter();
        this.interceptor = new com.sylo.kylo.core.security.SecurityInterceptor(engine);
    }

    public void setCurrentDb(String db) {
        this.currentDb = db;
    }

    public void executeQuery(String sql, OutputStream out, byte sequenceId) throws IOException {
        // Strip comments /**/
        String cleanSql = sql.replaceAll("/\\*.*?\\*/", "").trim();
        String upper = cleanSql.toUpperCase();

        try {
            if (upper.startsWith("SELECT")) {
                if (upper.contains("@@")) {
                    handleSystemSelect(cleanSql, out, sequenceId);
                } else {
                    handleSelect(cleanSql, out, sequenceId);
                }
            } else if (upper.startsWith("INSERT")) {
                handleInsert(cleanSql, out, sequenceId);
            } else if (upper.startsWith("CREATE")) {
                handleCreate(cleanSql, out, sequenceId);
            } else if (upper.startsWith("SET")) {
                // Ignore SET commands (SET NAMES, SET autocommit, etc) - pretend success
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++sequenceId);
            } else if (upper.startsWith("USE")) {
                String[] p = cleanSql.split("\\s+");
                if (p.length > 1) {
                    String db = p[1].replace(";", "");
                    // Compatibility: Alias mysql -> kylo_system
                    if (db.equalsIgnoreCase("mysql"))
                        db = "kylo_system";
                    this.currentDb = db;
                }
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++sequenceId);
            } else if (upper.startsWith("SHOW DATABASES")) {
                handleShowDatabases(out, sequenceId);
            } else if (upper.startsWith("SELECT DATABASE()")) {
                handleSelectDatabase(out, sequenceId);
            } else if (upper.startsWith("SHOW TABLES")) {
                handleShowTables(out, sequenceId);
            } else if (upper.startsWith("CREATE USER")) {
                handleCreateUser(cleanSql, out, sequenceId);
            } else if (upper.startsWith("DROP USER")) {
                handleDropUser(cleanSql, out, sequenceId);
            } else if (upper.startsWith("ALTER USER")) {
                handleAlterUser(cleanSql, out, sequenceId);
            } else if (upper.startsWith("REVOKE")) {
                handleRevoke(cleanSql, out, sequenceId);
            } else if (upper.startsWith("GRANT")) {
                handleGrant(cleanSql, out, sequenceId);
            } else if (upper.startsWith("FLUSH PRIVILEGES")) {
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++sequenceId);
            } else {
                MySQLPacket.writePacket(out,
                        PacketBuilder.buildError(1000, "KyloDB: Command not supported via MySQL Protocol yet: " + sql),
                        ++sequenceId);
            }
        } catch (Exception e) {
            e.printStackTrace();
            MySQLPacket.writePacket(out, PacketBuilder.buildError(500, "Execution Error: " + e.getMessage()),
                    ++sequenceId);
        }
    }

    private void handleSystemSelect(String sql, OutputStream out, byte seq) throws IOException {
        // Mock system variables for DBeaver introspection
        int cols = sql.split(" AS ").length - 1;
        if (cols < 1)
            cols = 1;

        List<com.sylo.kylo.core.catalog.Column> schemaCols = new ArrayList<>();
        List<Object> rowData = new ArrayList<>();

        for (int i = 0; i < cols; i++) {
            schemaCols.add(new com.sylo.kylo.core.catalog.Column("var" + i,
                    new com.sylo.kylo.core.structure.KyloVarchar(255), false));
            rowData.add("1");
        }

        Schema s = new Schema(schemaCols);
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

        String table = parts[fromIdx + 1].replace(";", "");
        String fullTable = currentDb + ":" + table;

        // Security Check
        interceptor.checkPermission(currentDb, table, "SELECT");

        Schema schema = Catalog.getInstance().getTableSchema(fullTable);
        if (schema == null) {
            throw new Exception("Table '" + table + "' not found.");
        }

        List<Object[]> rows = engine.scanTable(fullTable);
        rsWriter.writeResultSet(out, rows, schema, seq);
    }

    private void handleShowTables(OutputStream out, byte seq) throws Exception {
        List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
        cols.add(new com.sylo.kylo.core.catalog.Column("Tables_in_kylo",
                new com.sylo.kylo.core.structure.KyloVarchar(255), false));
        Schema s = new Schema(cols);

        List<Object[]> rows = new ArrayList<>();
        var all = Catalog.getInstance().getTables();
        for (String k : all.keySet()) {
            rows.add(new Object[] { k });
        }

        rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleInsert(String sql, OutputStream out, byte seq) {
        try {
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1000,
                    "INSERT via MySQL Protocol pending refactor of Parser. Use Visual Constructor."), ++seq);
        } catch (IOException e) {
            e.printStackTrace();
        }
    }

    private void handleCreate(String sql, OutputStream out, byte seq) {
        try {
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1000,
                    "CREATE via MySQL Protocol pending refactor of Parser. Use Visual Constructor."), ++seq);
        } catch (IOException e) {
            e.printStackTrace();
        }
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
            engine.deleteTuple("kylo_system:tables_privs",
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

            engine.deleteTuple("kylo_system:tables_privs",
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
                Object[] newTuple = new Object[] { host, user, newHash, existing[3] };
                engine.updateTuple("kylo_system:users", newTuple,
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
            engine.insertTuple("kylo_system:tables_privs", tuple);

            MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++seq);
        } else {
            MySQLPacket.writePacket(out, PacketBuilder.buildError(1064, "Syntax Error in GRANT"), ++seq);
        }
    }
}
