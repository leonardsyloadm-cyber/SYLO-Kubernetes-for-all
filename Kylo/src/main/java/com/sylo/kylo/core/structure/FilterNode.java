package com.sylo.kylo.core.structure;

import java.util.function.Predicate;

public class FilterNode extends PlanNode {
    private PlanNode child;
    private Predicate<Tuple> predicate;

    public FilterNode(PlanNode child, Predicate<Tuple> predicate) {
        this.child = child;
        this.predicate = predicate;
    }

    @Override
    public void open() {
        child.open();
    }

    @Override
    public Tuple next() {
        while (true) {
            Tuple t = child.next();
            if (t == null) {
                return null;
            }
            if (predicate.test(t)) {
                return t;
            }
        }
    }

    @Override
    public void close() {
        child.close();
    }
}
