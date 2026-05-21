package com.sylo.kylo;

import net.sf.jsqlparser.parser.CCJSqlParserUtil;
import net.sf.jsqlparser.statement.Statement;
import net.sf.jsqlparser.statement.select.Select;
import net.sf.jsqlparser.statement.select.PlainSelect;
import net.sf.jsqlparser.statement.insert.Insert;

public class JsqlTest {
    public static void main(String[] args) throws Exception {
        Statement stmt = CCJSqlParserUtil.parse("SELECT a, b FROM table1 WHERE a = 1");
        System.out.println(stmt.getClass());
        if (stmt instanceof Select) {
            Select sel = (Select) stmt;
            System.out.println("SELECT: " + sel);
            PlainSelect plainSelect = (PlainSelect) sel.getSelectBody();
            System.out.println("SelectBody: " + plainSelect.getClass());
            System.out.println("Cols: " + plainSelect.getSelectItems());
        }
        
        Statement stmt2 = CCJSqlParserUtil.parse("INSERT INTO table1 (a,b) VALUES (1,2)");
        if (stmt2 instanceof Insert) {
            Insert ins = (Insert) stmt2;
            System.out.println("INSERT TABLE: " + ins.getTable().getName());
            System.out.println("ITEMS: " + ins.getItemsList().getClass());
            System.out.println("ITEMS CONTENT: " + ins.getItemsList());
        }
    }
}
