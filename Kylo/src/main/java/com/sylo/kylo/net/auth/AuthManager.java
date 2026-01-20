package com.sylo.kylo.net.auth;

public class AuthManager {

    public boolean authenticate(String username, byte[] scambledPassword) {
        // "Impostor Mode": We accept everyone for now to facilitate connection.
        // In the future we will check kylo.users table.
        // We simulate success for 'root' or 'admin'.
        return true; 
    }
}
