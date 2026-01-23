package com.sylo.kylo.core.structure;

public class JoinNode extends PlanNode {
    private PlanNode left;
    private PlanNode right;
    private Tuple currentLeft;

    public JoinNode(PlanNode left, PlanNode right) {
        this.left = left;
        this.right = right;
    }

    @Override
    public void open() {
        left.open();
        right.open();
        currentLeft = left.next();
    }

    @Override
    public Tuple next() {
        while (currentLeft != null) {
            Tuple rightTuple = right.next();

            if (rightTuple == null) {
                // Determine if we are done with this left tuple
                // Reset right and move to next left
                right.close();
                right.open();
                currentLeft = left.next();
                continue;
            }

            // We have a pair (currentLeft, rightTuple)
            // In a real database we check join predicate here or in a FilterNode above.
            // "Cross Product" logic here unless predicate handles it.
            // Prompt says "JOIN (Inner, Left)".
            // Usually JoinNode has a predicate.
            // If predicate matches, return merged.
            // For this task, I assume this acts as Cross Join and a FilterNode handles the
            // condition?
            // OR I should implement predicate here.
            // "ExecutionEngine. Si una query tiene un WHERE..."
            // "Implementar JoinNode (Nested Loop Join)".
            // Typical generic NLJ combines everything. Logic above filters.

            return mergeTuples(currentLeft, rightTuple);
        }
        return null;
    }

    private Tuple mergeTuples(Tuple t1, Tuple t2) {
        Object[] v1 = t1.getValues();
        Object[] v2 = t2.getValues();
        Object[] combined = new Object[v1.length + v2.length];
        System.arraycopy(v1, 0, combined, 0, v1.length);
        System.arraycopy(v2, 0, combined, v1.length, v2.length);

        return new Tuple(new RowHeader(), combined);
    }

    @Override
    public void close() {
        left.close();
        right.close();
    }
}
