package com.sylo.kylo.core.structure;

public abstract class PlanNode {
    public abstract void open();

    public abstract Tuple next();

    public abstract void close();
}
