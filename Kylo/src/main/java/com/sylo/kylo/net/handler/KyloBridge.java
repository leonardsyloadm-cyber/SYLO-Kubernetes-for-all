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

public class KyloBridge {
    private final ExecutionEngine engine;
    private final ResultSetWriter rsWriter;

    public KyloBridge(ExecutionEngine engine) {
        this.engine = engine;
        this.rsWriter = new ResultSetWriter();
    }

    public void executeQuery(String sql, OutputStream out, byte sequenceId) throws IOException {
        // Strip comments /**/
        String cleanSql = sql.replaceAll("/\\*.*?\\*/", "").trim();
        String upper = cleanSql.toUpperCase();

        try {
            // Normalize spaces to single space to handle "SELECT  @@"
            String normalized = upper.replaceAll("\\s+", " ");
            
            if (normalized.startsWith("SELECT @@")) {
                handleSystemSelect(cleanSql, out, sequenceId);
            } else if (upper.startsWith("SELECT")) {
                handleSelect(cleanSql, out, sequenceId);
            } else if (upper.startsWith("INSERT")) {
                handleInsert(cleanSql, out, sequenceId);
            } else if (upper.startsWith("CREATE")) {
                handleCreate(cleanSql, out, sequenceId);
            } else if (upper.startsWith("SET")) {
                // Ignore SET commands (SET NAMES, SET autocommit, etc) - pretend success
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++sequenceId);
            } else if (upper.startsWith("USE")) {
                MySQLPacket.writePacket(out, PacketBuilder.buildOk(0, 0), ++sequenceId);
            } else if (upper.startsWith("SHOW DATABASES")) {
                handleShowDatabases(out, sequenceId);
            } else if (upper.startsWith("SELECT DATABASE()")) {
                handleSelectDatabase(out, sequenceId);
            } else if (upper.startsWith("SHOW TABLES")) {
                 handleShowTables(out, sequenceId);
            } else {
                MySQLPacket.writePacket(out, PacketBuilder.buildError(1000, "KyloDB: Command not supported via MySQL Protocol yet: " + sql), ++sequenceId);
            }
        } catch (Exception e) {
             e.printStackTrace();
             MySQLPacket.writePacket(out, PacketBuilder.buildError(500, "Execution Error: " + e.getMessage()), ++sequenceId);
        }
    }

    private void handleSystemSelect(String sql, OutputStream out, byte seq) throws IOException {
        // Mock system variables for DBeaver introspection
        int cols = sql.split(" AS ").length - 1;
        if (cols < 1) cols = 1;

        List<com.sylo.kylo.core.catalog.Column> schemaCols = new ArrayList<>();
        List<Object> rowData = new ArrayList<>();

        for(int i=0; i<cols; i++) {
            schemaCols.add(new com.sylo.kylo.core.catalog.Column("var"+i, new com.sylo.kylo.core.structure.KyloVarchar(255), false));
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
        for(int i=0; i<parts.length; i++) if(parts[i].equalsIgnoreCase("FROM")) fromIdx = i;
        
        if (fromIdx == -1 || fromIdx + 1 >= parts.length) {
            throw new Exception("Invalid SELECT syntax (Simple parser)");
        }
        
        String table = parts[fromIdx+1].replace(";", "");
        String fullTable = "default:" + table;
        
        Schema schema = Catalog.getInstance().getTableSchema(fullTable);
        if (schema == null) {
             throw new Exception("Table '" + table + "' not found.");
        }
        
        List<Object[]> rows = engine.scanTable(fullTable);
        rsWriter.writeResultSet(out, rows, schema, seq);
    }
    
    private void handleShowTables(OutputStream out, byte seq) throws Exception {
         List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
         cols.add(new com.sylo.kylo.core.catalog.Column("Tables_in_kylo", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
         Schema s = new Schema(cols);
         
         List<Object[]> rows = new ArrayList<>();
         var all = Catalog.getInstance().getTables();
         for(String k : all.keySet()) {
             rows.add(new Object[]{k});
         }
         
         rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleInsert(String sql, OutputStream out, byte seq) {
        try {
             MySQLPacket.writePacket(out, PacketBuilder.buildError(1000, "INSERT via MySQL Protocol pending refactor of Parser. Use Visual Constructor."), ++seq);
        } catch(IOException e) {}
    }

    private void handleCreate(String sql, OutputStream out, byte seq) {
         try {
             MySQLPacket.writePacket(out, PacketBuilder.buildError(1000, "CREATE via MySQL Protocol pending refactor of Parser. Use Visual Constructor."), ++seq);
        } catch(IOException e) {}
    }

    private void handleShowDatabases(OutputStream out, byte seq) throws IOException {
         List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
         cols.add(new com.sylo.kylo.core.catalog.Column("Database", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
         Schema s = new Schema(cols);
         
         List<Object[]> rows = new ArrayList<>();
         rows.add(new Object[]{"default"});
         rows.add(new Object[]{"kylo_system"});
         
         rsWriter.writeResultSet(out, rows, s, seq);
    }

    private void handleSelectDatabase(OutputStream out, byte seq) throws IOException {
         List<com.sylo.kylo.core.catalog.Column> cols = new ArrayList<>();
         cols.add(new com.sylo.kylo.core.catalog.Column("DATABASE()", new com.sylo.kylo.core.structure.KyloVarchar(255), false));
         Schema s = new Schema(cols);
         
         List<Object[]> rows = new ArrayList<>();
         rows.add(new Object[]{"default"});
         
         rsWriter.writeResultSet(out, rows, s, seq);
    }
}
