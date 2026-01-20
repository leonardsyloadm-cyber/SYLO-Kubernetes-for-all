package com.sylo.kylo.core.catalog;

import com.sylo.kylo.core.structure.KyloType;

public class Column {
    private final String name;
    private final KyloType type;
    private final boolean nullable;

    public Column(String name, KyloType type, boolean nullable) {
        this.name = name;
        this.type = type;
        this.nullable = nullable;
    }

    public String getName() {
        return name;
    }

    public KyloType getType() {
        return type;
    }

    public boolean isNullable() {
        return nullable;
    }
}
