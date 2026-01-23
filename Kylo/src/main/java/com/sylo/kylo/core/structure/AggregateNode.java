package com.sylo.kylo.core.structure;

import java.util.*;

public class AggregateNode extends PlanNode {
    public enum AggType {
        COUNT, SUM, AVG
    }

    public static class AggCall {
        public AggType type;
        public int colIndex; // -1 for COUNT(*)

        public AggCall(AggType type, int colIndex) {
            this.type = type;
            this.colIndex = colIndex;
        }
    }

    private PlanNode child;
    private List<Integer> groupByIndex;
    private List<AggCall> aggCalls;
    private Iterator<Tuple> resultIterator;

    public AggregateNode(PlanNode child, List<Integer> groupByIndex, List<AggCall> aggCalls) {
        this.child = child;
        this.groupByIndex = groupByIndex;
        this.aggCalls = aggCalls;
    }

    @Override
    public void open() {
        child.open();
        Map<List<Object>, List<Double>> accumulator = new HashMap<>(); // Key -> [AggValues (running sum/count)]
        Map<List<Object>, Integer> groupCounts = new HashMap<>(); // Separate count for AVG calculation

        while (true) {
            Tuple t = child.next();
            if (t == null)
                break;

            List<Object> key = new ArrayList<>();
            for (int idx : groupByIndex) {
                key.add(t.getValue(idx));
            }

            accumulator.putIfAbsent(key, new ArrayList<>());
            groupCounts.putIfAbsent(key, 0);

            List<Double> aggs = accumulator.get(key);
            // Init if empty
            if (aggs.isEmpty()) {
                for (int i = 0; i < aggCalls.size(); i++)
                    aggs.add(0.0);
            }

            groupCounts.put(key, groupCounts.get(key) + 1);

            for (int i = 0; i < aggCalls.size(); i++) {
                AggCall call = aggCalls.get(i);
                double currentVal = aggs.get(i);

                if (call.type == AggType.COUNT) {
                    aggs.set(i, currentVal + 1);
                } else {
                    Object valObj = (call.colIndex >= 0) ? t.getValue(call.colIndex) : null;
                    double val = 0.0;
                    if (valObj instanceof Number) {
                        val = ((Number) valObj).doubleValue();
                    }
                    if (call.type == AggType.SUM || call.type == AggType.AVG) {
                        // For AVG we sum now and divide later
                        aggs.set(i, currentVal + val);
                    }
                }
            }
        }
        child.close();

        // Finalize results
        List<Tuple> results = new ArrayList<>();
        for (Map.Entry<List<Object>, List<Double>> entry : accumulator.entrySet()) {
            List<Object> key = entry.getKey();
            List<Double> aggs = entry.getValue();
            int count = groupCounts.get(key);

            Object[] resValues = new Object[key.size() + aggs.size()];
            int p = 0;
            for (Object k : key)
                resValues[p++] = k;

            for (int i = 0; i < aggCalls.size(); i++) {
                if (aggCalls.get(i).type == AggType.AVG) {
                    resValues[p++] = aggs.get(i) / count;
                } else {
                    // Return as integer if integer? keeping double for simplicity or casting
                    // If COUNT, it's integer usually.
                    if (aggCalls.get(i).type == AggType.COUNT) {
                        resValues[p++] = aggs.get(i).longValue();
                    } else {
                        resValues[p++] = aggs.get(i);
                    }
                }
            }
            results.add(new Tuple(new RowHeader(), resValues));
        }
        resultIterator = results.iterator();
    }

    @Override
    public Tuple next() {
        return (resultIterator != null && resultIterator.hasNext()) ? resultIterator.next() : null;
    }

    @Override
    public void close() {
        child.close();
    }
}
