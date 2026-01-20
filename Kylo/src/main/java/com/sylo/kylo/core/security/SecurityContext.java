package com.sylo.kylo.core.security;

public class SecurityContext {
    private static final ThreadLocal<SecurityContext> currentContext = new ThreadLocal<>();

    private final String user;
    private final String host;

    public SecurityContext(String user, String host) {
        this.user = user;
        this.host = host;
    }

    public static void set(String user, String host) {
        currentContext.set(new SecurityContext(user, host));
    }
    
    public static void clear() {
        currentContext.remove();
    }

    public static SecurityContext get() {
        return currentContext.get();
    }

    public String getUser() {
        return user;
    }

    public String getHost() {
        return host;
    }
}
