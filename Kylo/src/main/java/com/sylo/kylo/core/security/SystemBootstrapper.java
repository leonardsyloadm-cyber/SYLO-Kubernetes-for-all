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
        catalog.createDatabase("kylo_system"); // Register the DB explicitly for UI visibility
        catalog.createTable("kylo_system:users", usersSchema);
        catalog.createTable("kylo_system:db_privs", dbPrivsSchema);
        catalog.createTable("kylo_system:table_privs", tablesPrivsSchema);

        // Advanced SQL System Tables
        catalog.createTable("kylo_system:proc", defineProcSchema());
        catalog.createTable("kylo_system:views", defineViewsSchema());
        catalog.createTable("kylo_system:triggers", defineTriggersSchema());
        catalog.createTable("kylo_system:events", defineEventsSchema());

        // 2b. Sanitize System Constraints (Prevent corruption from bad previous runs)
        com.sylo.kylo.core.constraint.ConstraintManager cm = com.sylo.kylo.core.constraint.ConstraintManager
                .getInstance();
        cm.clearConstraints("kylo_system:users");
        cm.clearConstraints("kylo_system:db_privs");
        cm.clearConstraints("kylo_system:table_privs"); // Corrected name

        // 2c. Register System Constraints (Primary Keys)
        try {
            // users PK: (Host, User)
            cm.addConstraint(new com.sylo.kylo.core.constraint.Constraint(
                    "PRIMARY",
                    com.sylo.kylo.core.constraint.Constraint.Type.PRIMARY_KEY,
                    "kylo_system:users",
                    java.util.Arrays.asList("Host", "User")));

            // db_privs PK: (Host, User, Db)
            cm.addConstraint(new com.sylo.kylo.core.constraint.Constraint(
                    "PRIMARY",
                    com.sylo.kylo.core.constraint.Constraint.Type.PRIMARY_KEY,
                    "kylo_system:db_privs",
                    java.util.Arrays.asList("Host", "User", "Db")));

            // table_privs PK: (Host, User, Db, Table_Name)
            cm.addConstraint(new com.sylo.kylo.core.constraint.Constraint(
                    "PRIMARY",
                    com.sylo.kylo.core.constraint.Constraint.Type.PRIMARY_KEY,
                    "kylo_system:table_privs",
                    java.util.Arrays.asList("Host", "User", "Db", "Table_Name")));

            System.out.println("✅ System Constraints registered.");
        } catch (Exception e) {
            System.err.println("⚠️ Warning registering system constraints: " + e.getMessage());
        }

        // 3. Check if root user exists. If not, create it.
        if (!userExists("root", "localhost")) {
            System.out
                    .println("⚠️ No users found. Creating default 'root'@'localhost' (No password for initial setup).");
            createRootUser();
        } else {
            System.out.println("✅ System users verified.");
        }
    }

    // Helper method to avoid duplication
    private void addCommonAuthColumns(List<Column> cols, boolean includeDb) {
        cols.add(new Column("Host", new KyloVarchar(255), false));
        cols.add(new Column("User", new KyloVarchar(255), false));
        if (includeDb) {
            cols.add(new Column("Db", new KyloVarchar(255), false));
        }
    }

    private Schema defineUsersSchema() {
        List<Column> cols = new ArrayList<>();
        addCommonAuthColumns(cols, false);
        cols.add(new Column("Password_Hash", new KyloVarchar(255), true)); // Null for empty pass
        cols.add(new Column("Super_Priv", new KyloBoolean(), false));
        return new Schema(cols);
    }

    private Schema defineDbPrivsSchema() {
        List<Column> cols = new ArrayList<>();
        addCommonAuthColumns(cols, true);
        cols.add(new Column("Access_Type", new KyloVarchar(50), false)); // SELECT, INSERT, etc.
        return new Schema(cols);
    }

    private Schema defineTablePrivsSchema() {
        List<Column> cols = new ArrayList<>();
        addCommonAuthColumns(cols, true);
        cols.add(new Column("Table_Name", new KyloVarchar(255), false));
        cols.add(new Column("Table_Priv", new KyloVarchar(50), false));
        return new Schema(cols);
    }

    private Schema defineProcSchema() {
        List<Column> cols = new ArrayList<>();
        cols.add(new Column("db", new KyloVarchar(64), false));
        cols.add(new Column("name", new KyloVarchar(64), false));
        cols.add(new Column("type", new KyloVarchar(10), false)); // FUNCTION, PROCEDURE
        cols.add(new Column("language", new KyloVarchar(10), false)); // SQL, LUA, JS
        cols.add(new Column("param_list", new KyloVarchar(1024), true));
        cols.add(new Column("returns", new KyloVarchar(1024), true));
        cols.add(new Column("body", new KyloVarchar(65535), false)); // Long text
        cols.add(new Column("is_deterministic", new KyloBoolean(), false));
        cols.add(new Column("created", new KyloVarchar(30), false));
        cols.add(new Column("modified", new KyloVarchar(30), false));
        return new Schema(cols);
    }

    private Schema defineViewsSchema() {
        List<Column> cols = new ArrayList<>();
        cols.add(new Column("table_schema", new KyloVarchar(64), false));
        cols.add(new Column("table_name", new KyloVarchar(64), false)); // View name
        cols.add(new Column("view_definition", new KyloVarchar(65535), false));
        cols.add(new Column("is_updatable", new KyloBoolean(), false));
        return new Schema(cols);
    }

    private Schema defineTriggersSchema() {
        List<Column> cols = new ArrayList<>();
        cols.add(new Column("trigger_schema", new KyloVarchar(64), false));
        cols.add(new Column("trigger_name", new KyloVarchar(64), false));
        cols.add(new Column("event_object_table", new KyloVarchar(64), false));
        cols.add(new Column("action_timing", new KyloVarchar(10), false)); // BEFORE, AFTER
        cols.add(new Column("event_manipulation", new KyloVarchar(10), false)); // INSERT, UPDATE, DELETE
        cols.add(new Column("action_statement", new KyloVarchar(65535), false));
        return new Schema(cols);
    }

    private Schema defineEventsSchema() {
        List<Column> cols = new ArrayList<>();
        cols.add(new Column("event_schema", new KyloVarchar(64), false));
        cols.add(new Column("event_name", new KyloVarchar(64), false));
        cols.add(new Column("status", new KyloVarchar(20), false)); // ENABLED, DISABLED
        cols.add(new Column("execute_at", new KyloVarchar(30), true));
        cols.add(new Column("interval_value", new KyloInt(), true));
        cols.add(new Column("interval_field", new KyloVarchar(20), true));
        return new Schema(cols);
    }

    private boolean userExists(String user, String host) {
        // Need to scan kylo_system:users
        // This is inefficient but runs only at startup.
        try {
            List<Object[]> users = executionEngine.scanTable("kylo_system:users");
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
        executionEngine.insertTuple("kylo_system:users", rootTuple);

        Object[] rootWildcard = new Object[] {
                "%",
                "root",
                null, // No password
                true // Super Priv
        };
        executionEngine.insertTuple("kylo_system:users", rootWildcard);
    }
}
