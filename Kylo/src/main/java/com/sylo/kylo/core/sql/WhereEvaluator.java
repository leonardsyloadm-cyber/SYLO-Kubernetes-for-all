package com.sylo.kylo.core.sql;

import com.sylo.kylo.core.catalog.Schema;
import com.sylo.kylo.core.structure.Tuple;
import net.sf.jsqlparser.expression.*;
import net.sf.jsqlparser.expression.operators.conditional.AndExpression;
import net.sf.jsqlparser.expression.operators.conditional.OrExpression;
import net.sf.jsqlparser.expression.operators.relational.*;
import net.sf.jsqlparser.schema.Column;
import java.util.function.Predicate;
import net.sf.jsqlparser.parser.CCJSqlParserUtil;

public class WhereEvaluator {

    public static Predicate<Tuple> buildPredicate(String whereClause, Schema schema) {
        if (whereClause == null || whereClause.trim().isEmpty()) return null;
        try {
            Expression expr = CCJSqlParserUtil.parseCondExpression(whereClause);
            return t -> {
                try {
                    return evaluate(expr, t, schema);
                } catch (Exception e) {
                    System.err.println("Predicate eval error: " + e.getMessage());
                    return false;
                }
            };
        } catch (Exception e) {
            System.err.println("Failed to parse WHERE clause with JSqlParser: " + e.getMessage());
            return null; // Fallback to no filter if parsing fails
        }
    }

    private static boolean evaluate(Expression expr, Tuple t, Schema schema) throws Exception {
        if (expr instanceof AndExpression) {
            AndExpression and = (AndExpression) expr;
            return evaluate(and.getLeftExpression(), t, schema) && evaluate(and.getRightExpression(), t, schema);
        } else if (expr instanceof OrExpression) {
            OrExpression or = (OrExpression) expr;
            return evaluate(or.getLeftExpression(), t, schema) || evaluate(or.getRightExpression(), t, schema);
        } else if (expr instanceof EqualsTo) {
            EqualsTo eq = (EqualsTo) expr;
            Object left = extractValue(eq.getLeftExpression(), t, schema);
            Object right = extractValue(eq.getRightExpression(), t, schema);
            if (left == null || right == null) return left == right;
            return left.toString().equals(right.toString()); // Simple comparison
        } else if (expr instanceof NotEqualsTo) {
            NotEqualsTo neq = (NotEqualsTo) expr;
            Object left = extractValue(neq.getLeftExpression(), t, schema);
            Object right = extractValue(neq.getRightExpression(), t, schema);
            if (left == null || right == null) return left != right;
            return !left.toString().equals(right.toString());
        } else if (expr instanceof Parenthesis) {
            return evaluate(((Parenthesis) expr).getExpression(), t, schema);
        }
        // Support can be extended here for >, <, LIKE, etc.
        throw new Exception("Unsupported expression: " + expr.getClass().getSimpleName());
    }

    private static Object extractValue(Expression expr, Tuple t, Schema schema) throws Exception {
        if (expr instanceof Column) {
            String colName = ((Column) expr).getColumnName();
            int idx = -1;
            for (int i = 0; i < schema.getColumnCount(); i++) {
                if (schema.getColumn(i).getName().equalsIgnoreCase(colName)) {
                    idx = i;
                    break;
                }
            }
            if (colName.equalsIgnoreCase("true")) return true;
            if (colName.equalsIgnoreCase("false")) return false;
            
            if (idx == -1) {
                StringBuilder avail = new StringBuilder();
                for (int i = 0; i < schema.getColumnCount(); i++) avail.append(schema.getColumn(i).getName()).append(",");
                throw new Exception("Column not found: " + colName + ". Available: " + avail.toString());
            }
            return t.getValue(idx);
        } else if (expr instanceof StringValue) {
            return ((StringValue) expr).getValue();
        } else if (expr instanceof LongValue) {
            return ((LongValue) expr).getValue();
        } else if (expr instanceof DoubleValue) {
            return ((DoubleValue) expr).getValue();
        }
        throw new Exception("Unsupported value type: " + expr.getClass().getSimpleName());
    }
}
