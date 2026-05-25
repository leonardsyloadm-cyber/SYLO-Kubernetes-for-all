import com.sylo.kylo.core.sql.OracleDialectTranslator;

public class OracleTest {
    public static void main(String[] args) {
        String q1 = "SELECT SYSDATE FROM DUAL";
        String q2 = "SELECT * FROM users WHERE ROWNUM <= 10";
        String q3 = "SELECT NVL(name, 'Unknown') FROM employees";
        
        System.out.println("Original: " + q1);
        System.out.println("Translated: " + OracleDialectTranslator.translate(q1));
        
        System.out.println("\nOriginal: " + q2);
        System.out.println("Translated: " + OracleDialectTranslator.translate(q2));
        
        System.out.println("\nOriginal: " + q3);
        System.out.println("Translated: " + OracleDialectTranslator.translate(q3));
    }
}
