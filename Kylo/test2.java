import net.sf.jsqlparser.parser.CCJSqlParserUtil;
import net.sf.jsqlparser.statement.Statement;
import net.sf.jsqlparser.statement.insert.Insert;

public class test2 {
    public static void main(String[] args) throws Exception {
        Statement stmt = CCJSqlParserUtil.parse("INSERT INTO testdb.users (id) VALUES (1)");
        Insert ins = (Insert) stmt;
        System.out.println("Name: " + ins.getTable().getName());
        System.out.println("Schema: " + ins.getTable().getSchemaName());
    }
}
