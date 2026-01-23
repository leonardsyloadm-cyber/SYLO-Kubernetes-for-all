package com.sylo.kylo.core.session;

import java.util.HashMap;
import java.util.Map;

/**
 * Manages the session state for a client connection.
 * Handles MySQL variables (@@variable) and session-specific settings.
 */
public class SessionContext {
    private final Map<String, Object> variables = new HashMap<>();
    private String currentDatabase = "default";
    private String currentUser = null;

    public SessionContext() {
        // Initialize default MySQL 8.0 variables to satisfy JDBC/DBeaver
        variables.put("max_allowed_packet", 67108864); // 64M
        variables.put("net_write_timeout", 60);
        variables.put("net_read_timeout", 30);
        variables.put("auto_increment_increment", 1);
        variables.put("tx_isolation", "REPEATABLE-READ");
        variables.put("transaction_isolation", "REPEATABLE-READ"); // Alias
        variables.put("system_time_zone", "UTC");
        variables.put("time_zone", "SYSTEM");
        variables.put("autocommit", 1);
        variables.put("character_set_client", "utf8mb4");
        variables.put("character_set_connection", "utf8mb4");
        variables.put("character_set_results", "utf8mb4");
        variables.put("character_set_server", "utf8mb4");
        variables.put("collation_server", "utf8mb4_0900_ai_ci");
        variables.put("collation_connection", "utf8mb4_0900_ai_ci");

        // Mock version for handshake
        variables.put("version", "8.0.30-KyloDB");
        variables.put("version_comment", "KyloDB Community Server (GPL)");
        variables.put("lower_case_table_names", 1); // Case insensitive names
        variables.put("license", "GPL");
        variables.put("init_connect", "");
        variables.put("interactive_timeout", 28800);
        variables.put("wait_timeout", 28800);
        variables.put("performance_schema", 0);
        variables.put("sql_mode", "STRICT_TRANS_TABLES");
        variables.put("default_storage_engine", "KyloDB");
        variables.put("auto_increment_offset", 1);
        variables.put("lower_case_file_system", 0); // Case sensitive on Linux
        variables.put("log_bin_trust_function_creators", 1);
        variables.put("net_buffer_length", 16384);
        variables.put("max_sp_recursion_depth", 0);
        variables.put("sql_safe_updates", 0);
        variables.put("query_cache_type", 0);
        variables.put("query_cache_size", 0);
        variables.put("transaction_read_only", 0);
        variables.put("read_only", 0);
        variables.put("session_track_schema", 1);
        variables.put("session_track_system_variables", "");
        variables.put("session_track_state_change", 0);
        variables.put("session_track_transaction_info", "");
    }

    public void setVariable(String name, Object value) {
        variables.put(name.toLowerCase(), value);
    }

    public Object getVariable(String name) {
        return variables.get(name.toLowerCase());
    }

    public Map<String, Object> getAllVariables() {
        return new HashMap<>(variables);
    }

    public String getCurrentDatabase() {
        return currentDatabase;
    }

    public void setCurrentDatabase(String currentDatabase) {
        this.currentDatabase = currentDatabase;
    }

    public String getCurrentUser() {
        return currentUser;
    }

    public void setCurrentUser(String currentUser) {
        this.currentUser = currentUser;
    }
}
