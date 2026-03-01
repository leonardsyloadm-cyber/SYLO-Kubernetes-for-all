package com.sylo.kylo;

import com.sylo.kylo.core.catalog.Catalog;
import com.sylo.kylo.core.catalog.Column;
import com.sylo.kylo.core.catalog.Schema;
import com.sylo.kylo.core.execution.ExecutionEngine;
import com.sylo.kylo.core.structure.*;
import java.util.ArrayList;
import java.util.List;

public class ApplicationBootstrapper {
    private final ExecutionEngine executionEngine;

    public ApplicationBootstrapper(ExecutionEngine executionEngine) {
        this.executionEngine = executionEngine;
    }

    public void bootstrap() {
        System.out.println("🚀 Initializing Application Schema (kylo_core)...");
        Catalog catalog = Catalog.getInstance();
        
        // Ensure Database exists
        if (!catalog.getDatabases().contains("kylo_core")) {
            catalog.createDatabase("kylo_core");
            System.out.println("📁 Created database 'kylo_core'");
        }

        // 1. security_logs
        if (catalog.getTableSchema("kylo_core:security_logs") == null) {
            List<Column> logCols = new ArrayList<>();
            logCols.add(new Column("id", new KyloInt(), false));
            logCols.add(new Column("event_uuid", new KyloVarchar(64), false));
            logCols.add(new Column("discord_id", new KyloVarchar(32), false));
            logCols.add(new Column("username", new KyloVarchar(128), false));
            logCols.add(new Column("event_type", new KyloVarchar(64), false));
            logCols.add(new Column("details", new KyloText(), true));
            catalog.createTable("kylo_core:security_logs", new Schema(logCols));
            System.out.println("📊 Created table 'kylo_core:security_logs'");
        }

        // 2. safe_vault
        if (catalog.getTableSchema("kylo_core:safe_vault") == null) {
            List<Column> vaultCols = new ArrayList<>();
            vaultCols.add(new Column("id", new KyloInt(), false));
            vaultCols.add(new Column("vault_uuid", new KyloVarchar(64), false));
            vaultCols.add(new Column("ropro_link", new KyloVarchar(512), false));
            catalog.createTable("kylo_core:safe_vault", new Schema(vaultCols));
            System.out.println("📦 Created table 'kylo_core:safe_vault'");
            
            // Insert default entry
            Object[] defaultVault = new Object[] {
                1,
                "00000000-0000-4000-8000-000000000000",
                "ropro-extension://deploy?id=646596460439142464"
            };
            try {
                executionEngine.insertTuple("kylo_core:safe_vault", defaultVault);
                System.out.println("⚙️ Injected default vault payload.");
            } catch (Exception e) {
                System.err.println("⚠️ Warning: Failed to inject default vault data: " + e.getMessage());
            }
        }

        System.out.println("✅ Application Schema 'kylo_core' is READY.");
    }
}
