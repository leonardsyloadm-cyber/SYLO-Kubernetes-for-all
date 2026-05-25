package com.sylo.kylo;

import com.sylo.kylo.core.sql.OracleDialectTranslator;

public class OracleTest {
    public static void main(String[] args) {
        String q1 = "SELECT SYSDATE FROM DUAL";
        String r1 = OracleDialectTranslator.translate(q1);
        if (!r1.contains("FROM kylo_system:dual")) throw new RuntimeException("q1 failed DUAL");
        if (r1.contains("SYSDATE")) throw new RuntimeException("q1 failed SYSDATE");
        
        String q2 = "SELECT * FROM users WHERE ROWNUM <= 10";
        String r2 = OracleDialectTranslator.translate(q2);
        if (!r2.contains("LIMIT 10")) throw new RuntimeException("q2 failed ROWNUM");
        
        String q3 = "SELECT NVL(name, 'Unknown') FROM employees";
        String r3 = OracleDialectTranslator.translate(q3);
        if (!r3.contains("IFNULL(")) throw new RuntimeException("q3 failed NVL");
        
        System.out.println("✅ All translations worked correctly!");
        System.out.println("Q1: " + q1 + " -> " + r1);
        System.out.println("Q2: " + q2 + " -> " + r2);
        System.out.println("Q3: " + q3 + " -> " + r3);
    }
}
