package com.sylo.kylo.core.security;

import com.sylo.kylo.core.execution.ExecutionEngine;
import java.util.List;

public class SecurityInterceptor {
    private final ExecutionEngine executionEngine;

    public SecurityInterceptor(ExecutionEngine executionEngine) {
        this.executionEngine = executionEngine;
    }

    public void checkPermission(String db, String table, String requiredPriv) throws Exception {
        SecurityContext ctx = SecurityContext.get();
        if (ctx == null) {
            throw new Exception("Access Denied: No Security Context established.");
        }

        String user = ctx.getUser();

        // 1. Check Super Priv (Global)
        if (hasSuperPriv(user)) {
            return; // Allowed
        }

        // 2. Check DB Priv
        if (hasDbPriv(user, db, requiredPriv)) {
            return;
        }

        // 3. Check Table Priv
        if (table != null && hasTablePriv(user, db, table, requiredPriv)) {
            return;
        }

        throw new Exception("Access Denied for user '" + user + "' on " + db + (table != null ? "." + table : "")
                + ". Required: " + requiredPriv);
    }

    private boolean hasSuperPriv(String user) {
        try {
            List<Object[]> users = executionEngine.scanTable("SYSTEM:users");
            for (Object[] row : users) {
                if (user.equals(row[1])) {
                    Object val = row[3];
                    if (val instanceof Boolean && (Boolean) val)
                        return true;
                }
            }
        } catch (Exception e) {
        }
        return false;
    }

    private boolean hasDbPriv(String user, String db, String priv) {
        try {
            List<Object[]> rows = executionEngine.scanTable("SYSTEM:db_privs");
            for (Object[] row : rows) {
                // Host ignored for now for simplicity
                if (user.equals(row[1]) && db.equals(row[2])) {
                    // Check priv match (e.g. "SELECT" or "ALL")
                    String p = (String) row[3];
                    if (p.equalsIgnoreCase("ALL") || p.equalsIgnoreCase(priv))
                        return true;
                }
            }
        } catch (Exception e) {
        }
        return false;
    }

    private boolean hasTablePriv(String user, String db, String table, String priv) {
        try {
            List<Object[]> rows = executionEngine.scanTable("SYSTEM:tables_privs");
            for (Object[] row : rows) {
                if (user.equals(row[1]) && db.equals(row[2]) && table.equals(row[3])) {
                    String p = (String) row[4];
                    if (p.equalsIgnoreCase("ALL") || p.equalsIgnoreCase(priv))
                        return true;
                }
            }
        } catch (Exception e) {
        }
        return false;
    }
}
