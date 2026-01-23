package com.sylo.kylo.core.structure;

import java.util.*;

public class SortNode extends PlanNode {
    public static class Order {
        public int colIndex;
        public boolean ascending;

        public Order(int colIndex, boolean ascending) {
            this.colIndex = colIndex;
            this.ascending = ascending;
        }
    }

    private PlanNode child;
    private List<Order> sortOrders;
    private Iterator<Tuple> resultIterator;
    private static final int MAX_ROWS = 10000; // OOM safety limit

    public SortNode(PlanNode child, List<Order> sortOrders) {
        this.child = child;
        this.sortOrders = sortOrders;
    }

    @SuppressWarnings({ "unchecked", "rawtypes" })
    @Override
    public void open() {
        child.open();
        List<Tuple> buffer = new ArrayList<>();
        while (true) {
            Tuple t = child.next();
            if (t == null)
                break;
            buffer.add(t);
            if (buffer.size() > MAX_ROWS) {
                throw new RuntimeException("SortNode exceeded in-memory limit: " + MAX_ROWS);
            }
        }
        child.close();

        buffer.sort((t1, t2) -> {
            for (Order o : sortOrders) {
                Comparable v1 = (Comparable) t1.getValue(o.colIndex);
                Comparable v2 = (Comparable) t2.getValue(o.colIndex);
                // Handle nulls
                if (v1 == null && v2 == null)
                    continue;
                if (v1 == null)
                    return o.ascending ? -1 : 1;
                if (v2 == null)
                    return o.ascending ? 1 : -1;

                int cmp = v1.compareTo(v2);
                if (cmp != 0) {
                    return o.ascending ? cmp : -cmp;
                }
            }
            return 0;
        });

        resultIterator = buffer.iterator();
    }

    @Override
    public Tuple next() {
        return (resultIterator != null && resultIterator.hasNext()) ? resultIterator.next() : null;
    }

    @Override
    public void close() {
        // Clear buffer
        resultIterator = null;
    }
}
