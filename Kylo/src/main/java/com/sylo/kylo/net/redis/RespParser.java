package com.sylo.kylo.net.redis;

import java.io.IOException;
import java.io.InputStream;
import java.util.ArrayList;
import java.util.List;

public class RespParser {
    private final InputStream in;

    public RespParser(InputStream in) {
        this.in = in;
    }

    public Object readObject() throws IOException {
        int b = in.read();
        if (b == -1) return null;

        char type = (char) b;
        if (type == '*') { // Array (Commands are sent as arrays of bulk strings)
            return readArray();
        } else if (type == '$') { // Bulk String
            return readBulkString();
        } else if (type == '+') { // Simple String
            return readSimpleString();
        } else if (type == ':') { // Integer
            return readInteger();
        } else if (type == '-') { // Error
            return readSimpleString();
        }
        
        // Inline commands (like 'PING\r\n' via telnet)
        return readInlineCommand(type);
    }

    private List<String> readArray() throws IOException {
        int length = readLength();
        if (length == -1) return null; // Null array
        
        List<String> array = new ArrayList<>(length);
        for (int i = 0; i < length; i++) {
            int b = in.read();
            if (b == '$') {
                array.add(readBulkString());
            } else {
                throw new IOException("Expected Bulk String ($) inside command array");
            }
        }
        return array;
    }

    private String readBulkString() throws IOException {
        int length = readLength();
        if (length == -1) return null; // Null string
        
        byte[] data = new byte[length];
        int read = 0;
        while (read < length) {
            int r = in.read(data, read, length - read);
            if (r == -1) throw new IOException("Unexpected EOF reading Bulk String");
            read += r;
        }
        
        // Read \r\n
        in.read(); 
        in.read();
        
        return new String(data, java.nio.charset.StandardCharsets.UTF_8);
    }

    private String readSimpleString() throws IOException {
        StringBuilder sb = new StringBuilder();
        int b;
        while ((b = in.read()) != -1) {
            if (b == '\r') {
                in.read(); // Consume \n
                break;
            }
            sb.append((char) b);
        }
        return sb.toString();
    }

    private int readLength() throws IOException {
        return Integer.parseInt(readSimpleString());
    }

    private int readInteger() throws IOException {
        return readLength();
    }
    
    private List<String> readInlineCommand(char firstChar) throws IOException {
        StringBuilder sb = new StringBuilder();
        sb.append(firstChar);
        int b;
        while ((b = in.read()) != -1) {
            if (b == '\r') {
                in.read(); // consume \n
                break;
            }
            sb.append((char) b);
        }
        
        String[] parts = sb.toString().trim().split("\\s+");
        return java.util.Arrays.asList(parts);
    }
}
