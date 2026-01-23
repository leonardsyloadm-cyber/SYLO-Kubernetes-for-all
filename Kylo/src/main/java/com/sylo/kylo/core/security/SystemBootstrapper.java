package com.sylo.kylo.core.security;

import com.sylo.kylo.core.catalog.Catalog;
import com.sylo.kylo.core.catalog.Column;
import com.sylo.kylo.core.catalog.Schema;
import com.sylo.kylo.core.execution.ExecutionEngine;
import com.sylo.kylo.core.structure.*;
import java.util.ArrayList;
import java.util.List;

public class SystemBootstrapper {

    private final ExecutionEngine executionEngine;

    public SystemBootstrapper(ExecutionEngine executionEngine) {
        this.executionEngine = executionEngine;
    }

    public void bootstrap() {
        System.out.println("🛡️ Initializing Security Subsystem...");

        // 1. Define System Tables Schemas
        Schema usersSchema = defineUsersSchema();
        Schema dbPrivsSchema = defineDbPrivsSchema();
        Schema tablesPrivsSchema = defineTablePrivsSchema();

        // 2. Register in Catalog (Hardcoded persistence for now)
        Catalog catalog = Catalog.getInstance();
        catalog.createDatabase("SYSTEM"); // Register the DB explicitly for UI visibility
        catalog.createTable("SYSTEM:users", usersSchema);
        catalog.createTable("SYSTEM:db_privs", dbPrivsSchema);
        catalog.createTable("SYSTEM:tables_privs", tablesPrivsSchema);

        // 3. Check if root user exists. If not, create it.
        if (!userExists("root", "localhost")) {
            System.out
                    .println("⚠️ No users found. Creating default 'root'@'localhost' (No password for initial setup).");
            createRootUser();
        } else {
            System.out.println("✅ System users verified.");
        }
    }

    private Schema defineUsersSchema() {
        List<Column> cols = new ArrayList<>();
        cols.add(new Column("Host", new KyloVarchar(255), false));
        cols.add(new Column("User", new KyloVarchar(255), false));
        cols.add(new Column("Password_Hash", new KyloVarchar(255), true)); // Null for empty pass
        cols.add(new Column("Super_Priv", new KyloBoolean(), false));
        return new Schema(cols);
    }

    private Schema defineDbPrivsSchema() {
        List<Column> cols = new ArrayList<>();
        cols.add(new Column("Host", new KyloVarchar(255), false));
        cols.add(new Column("User", new KyloVarchar(255), false));
        cols.add(new Column("Db", new KyloVarchar(255), false));
        cols.add(new Column("Access_Type", new KyloVarchar(50), false)); // SELECT, INSERT, etc.
        return new Schema(cols);
    }

    private Schema defineTablePrivsSchema() {
        List<Column> cols = new ArrayList<>();
        cols.add(new Column("Host", new KyloVarchar(255), false));
        cols.add(new Column("User", new KyloVarchar(255), false));
        cols.add(new Column("Db", new KyloVarchar(255), false));
        cols.add(new Column("Table_Name", new KyloVarchar(255), false));
        cols.add(new Column("Table_Priv", new KyloVarchar(50), false));
        return new Schema(cols);
    }

    private boolean userExists(String user, String host) {
        // Need to scan kylo_system:users
        // This is inefficient but runs only at startup.
        try {
            List<Object[]> users = executionEngine.scanTable("SYSTEM:users");
            for (Object[] row : users) {
                String u = (String) row[1];
                String h = (String) row[0];
                if (u.equals(user) && (h.equals("%") || h.equals(host))) {
                    return true;
                }
            }
        } catch (Exception e) {
            // Maybe table empty or first run
            return false;
        }
        return false;
    }

    private void createRootUser() {
        // Insert root user
        // Cols: Host, User, Password_Hash, Super_Priv
        Object[] rootTuple = new Object[] {
                "localhost",
                "root",
                null, // No password
                true // Super Priv
        };
        executionEngine.insertTuple("SYSTEM:users", rootTuple);

        Object[] rootWildcard = new Object[] {
                "%",
                "root",
                null, // No password
                true // Super Priv
        };
        executionEngine.insertTuple("SYSTEM:users", rootWildcard);
    }
}
