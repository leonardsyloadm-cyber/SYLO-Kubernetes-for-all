package com.sylo.kylo.core.sql;

import java.time.LocalDateTime;
import java.time.format.DateTimeFormatter;

public class OracleDialectTranslator {

    public static String translate(String query) {
        String translated = query;

        // 1. DUAL Table and SYSDATE Replacement
        // If the query asks for SYSDATE from DUAL, return the actual date string wrapped in standard SELECT
        // E.g., SELECT SYSDATE FROM DUAL -> SELECT '2026-05-25 22:30:00' FROM kylo_system.dual
        if (translated.toUpperCase().matches("(?i).*\\bSYSDATE\\b.*FROM\\s+DUAL.*")) {
            String now = LocalDateTime.now().format(DateTimeFormatter.ofPattern("yyyy-MM-dd HH:mm:ss"));
            translated = translated.replaceAll("(?i)\\bSYSDATE\\b", "'" + now + "'");
        }

        // 2. DUAL Table normalization
        // Oracle uses DUAL without schema. We remap it to kylo_system.dual
        translated = translated.replaceAll("(?i)\\bFROM\\s+DUAL\\b", "FROM kylo_system:dual");

        // 3. ROWNUM to LIMIT translation (Very basic heuristic)
        // WHERE ROWNUM <= 10 -> LIMIT 10
        // Note: KyloDB LogicalPlanner doesn't fully support LIMIT natively yet in all paths, 
        // but removing ROWNUM prevents syntax errors
        if (translated.toUpperCase().contains("ROWNUM")) {
            translated = translated.replaceAll("(?i)\\bWHERE\\s+ROWNUM\\s*<=\\s*(\\d+)", "LIMIT $1");
            translated = translated.replaceAll("(?i)\\bAND\\s+ROWNUM\\s*<=\\s*(\\d+)", "LIMIT $1");
        }

        // 4. NVL() to IFNULL() or standard
        // Since KyloDB doesn't have an IFNULL function parser yet, we might just strip it or map it.
        // For now, mapping the keyword if we add it later.
        translated = translated.replaceAll("(?i)\\bNVL\\(", "IFNULL(");

        return translated;
    }
}
