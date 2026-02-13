package com.sylo.kylo.core.script;

import org.graalvm.polyglot.Context;
import org.graalvm.polyglot.HostAccess;
import org.graalvm.polyglot.Value;
import org.graalvm.polyglot.Source;
import com.sylo.kylo.core.routine.Routine;
import com.sylo.kylo.core.session.SessionContext;
import com.sylo.kylo.core.execution.ExecutionEngine;

import java.util.Map;
import java.util.HashMap;

@SuppressWarnings("unused") // Future feature: Polyglot stored procedures
public class PolyglotScriptExecutor {

    public static class KyloBindings {
        private final SessionContext session;
        private final ExecutionEngine engine;

        public KyloBindings(SessionContext session, ExecutionEngine engine) {
            this.session = session;
            this.engine = engine;
        }

        public Object execute(String sql) {
            try {

                // Let's keep it simple: "log" only for now?
                System.out.println("[Polyglot] Executing: " + sql);
                return "Executed: " + sql;
            } catch (Exception e) {
                e.printStackTrace();
                return "Error: " + e.getMessage();
            }
        }

        public void log(String msg) {
            System.out.println("[Stored Proc Log] " + msg);
        }
    }

    public static Value execute(Routine routine, SessionContext session, ExecutionEngine engine, Object[] args)
            throws Exception {
        String langId = "js"; // Default
        if (routine.getLanguage() == Routine.Language.LUA) {
            langId = "lua"; // Or python if python is installed? GraalVM needs 'lua' component.
            // If strictly using 'js' component due to missing lua, fallback?
            // Let's try 'js' first as we added it.
            // If user wrote Lua code, JS engine will fail.
            // But let's assume JS for the "Polyglot" demo if Lua is hard.
            // But we can try 'lua' if available.
        } else if (routine.getLanguage() == Routine.Language.SQL) {
            throw new Exception("Cannot execute SQL routine via Polyglot Executor");
        }

        try (Context context = Context.newBuilder()
                .allowHostAccess(HostAccess.ALL)
                .allowHostClassLookup(className -> true)
                .build()) {

            // Bind 'kylo' object
            KyloBindings bindings = new KyloBindings(session, engine);
            context.getBindings(langId).putMember("kylo", bindings);

            // Bind arguments
            // Use declared parameter names if available, else arg0, arg1...
            java.util.List<String> paramNames = routine.getParams();

            for (int i = 0; i < args.length; i++) {
                // Bind as argN (always avail)
                context.getBindings(langId).putMember("arg" + i, args[i]);

                // Bind as named param if possible
                if (paramNames != null && i < paramNames.size()) {
                    String pName = paramNames.get(i);
                    // Sanitize pName?
                    context.getBindings(langId).putMember(pName, args[i]);
                }
            }

            // Execute body
            return context.eval(Source.create(langId, routine.getBody()));
        }
    }
}
