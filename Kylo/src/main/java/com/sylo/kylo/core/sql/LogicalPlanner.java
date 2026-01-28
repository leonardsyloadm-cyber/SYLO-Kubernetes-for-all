package com.sylo.kylo.core.sql;

import com.sylo.kylo.core.catalog.Catalog;
import com.sylo.kylo.core.catalog.Schema;
import com.sylo.kylo.core.execution.ExecutionEngine;
import com.sylo.kylo.core.index.IndexManager;
import com.sylo.kylo.core.structure.*;

import java.util.function.Predicate;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

public class LogicalPlanner {
    private final ExecutionEngine engine;

    public LogicalPlanner(ExecutionEngine engine) {
        this.engine = engine;
    }

    /**
     * Creates a PlanNode for a SELECT query with potential optimization.
     * Currently supports simple "SELECT ... FROM table WHERE col = val"
     * optimization.
     */
    public PlanNode createSelectPlan(String tableName, String whereClause) {
        Catalog catalog = Catalog.getInstance();
        Schema schema = catalog.getTableSchema(tableName);
        if (schema == null) {
            throw new IllegalArgumentException("Table " + tableName + " does not exist");
        }

        // 1. Parse Where Clause for Optimization Candidates
        // Looking for: col = constant
        if (whereClause != null && !whereClause.isEmpty()) {
            PlanNode optimized = tryOptimize(tableName, whereClause, schema);
            if (optimized != null) {
                System.out.println("DEBUG: Optimization applied (IndexScan) for " + tableName);
                return optimized;
            }
        }

        // 2. Fallback to Full Scan
        // We need to build the Predicate from the whereClause if it exists
        Predicate<Tuple> predicate = null;
        if (whereClause != null && !whereClause.isEmpty()) {
            predicate = parsePredicate(whereClause, schema);
        }

        return engine.createScanPlan(tableName, predicate);
    }

    private PlanNode tryOptimize(String tableName, String whereClause, Schema schema) {
        // Simple Parser for "col = 'val'" or "col = 123"
        Pattern eqPattern = Pattern.compile("(\\w+)\\s*=\\s*(?:'([^']*)'|([^\\s]+))");
        Matcher m = eqPattern.matcher(whereClause);

        if (m.find()) {
            String colName = m.group(1);
            String valStr = m.group(2) != null ? m.group(2) : m.group(3);

            IndexManager idxMgr = catalog().getIndexManager();
            if (idxMgr.hasIndex(tableName, colName)) {
                // Found Index!
                // Convert value to correct type
                int colIdx = -1;
                for (int i = 0; i < schema.getColumnCount(); i++) {
                    if (schema.getColumn(i).getName().equals(colName)) {
                        colIdx = i;
                        break;
                    }
                }

                if (colIdx != -1) {
                    com.sylo.kylo.core.structure.KyloType type = schema.getColumn(colIdx).getType();
                    Object key = parseValue(type, valStr);

                    com.sylo.kylo.core.storage.BufferPoolManager bpm = engine.getBufferPool();
                    com.sylo.kylo.core.index.BPlusTreeIndex idx = idxMgr.getIndex(tableName, colName, bpm);

                    if (idx != null) {
                        return new com.sylo.kylo.core.structure.IndexScanNode(bpm, schema, idx, key);
                    }
                }
            }
        }
        return null; // No optimization found
    }

    // Helper to get Catalog
    private Catalog catalog() {
        return Catalog.getInstance();
    }

    // Quick Parser reuse (Duplicate from KyloProcessor, should be unified)
    private Object parseValue(com.sylo.kylo.core.structure.KyloType type, String raw) {
        // ... (Simplified logic)
        try {
            if (type.toString().contains("INT"))
                return Integer.parseInt(raw);
            if (type.toString().contains("EAN"))
                return Boolean.parseBoolean(raw);
        } catch (Exception e) {
        }
        return raw;
    }

    private Predicate<Tuple> parsePredicate(String where, Schema schema) {
        // Basic parser for "col = val"
        // Valid for "sexo = true", "id = 1", etc.
        Pattern eqPattern = Pattern.compile("(\\w+)\\s*=\\s*(?:'([^']*)'|([^\\s]+))");
        Matcher m = eqPattern.matcher(where);

        if (m.find()) {
            final String colName = m.group(1);
            String valStr = m.group(2) != null ? m.group(2) : m.group(3);

            // Find column index
            int idx = -1;
            com.sylo.kylo.core.structure.KyloType type = null;
            for (int i = 0; i < schema.getColumnCount(); i++) {
                if (schema.getColumn(i).getName().equals(colName)) {
                    idx = i;
                    type = schema.getColumn(i).getType();
                    break;
                }
            }

            if (idx == -1)
                return null; // Column not found

            final int colIdx = idx;
            final Object targetVal = parseValue(type, valStr);

            return new Predicate<Tuple>() {
                @Override
                public boolean test(Tuple t) {
                    Object val = t.getValue(colIdx);
                    if (val == null)
                        return targetVal == null;
                    return val.equals(targetVal);
                }
            };
        }
        return null;
    }
}
