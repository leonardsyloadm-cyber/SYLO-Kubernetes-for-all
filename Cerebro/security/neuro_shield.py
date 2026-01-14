import time
import re
import logging
from typing import Dict, Tuple
from fastapi import Request, Response
from starlette.middleware.base import BaseHTTPMiddleware
from starlette.types import ASGIApp

# Configure logging for security events
logging.basicConfig(level=logging.INFO)
security_logger = logging.getLogger("SYLO_NEURO_SHIELD")

class TokenBucket:
    def __init__(self, rate: int, capacity: int):
        self._rate = rate
        self._capacity = capacity
        self._tokens = capacity
        self._last_refill = time.time()

    def consume(self) -> bool:
        now = time.time()
        # Refill tokens based on time elapsed
        elapsed = now - self._last_refill
        refill_amount = elapsed * (self._rate / 60.0)
        self._tokens = min(self._capacity, self._tokens + refill_amount)
        self._last_refill = now

        if self._tokens >= 1:
            self._tokens -= 1
            return True
        return False

class NeuroShieldMiddleware(BaseHTTPMiddleware):
    def __init__(self, app: ASGIApp):
        super().__init__(app)
        # IP -> TokenBucket
        self._buckets: Dict[str, TokenBucket] = {}
        # 60 requests per minute
        self.RATE_LIMIT = 60
        self.CAPACITY = 60
        
        # Regex patterns for Sanitization
        self.SQLI_PATTERNS = [
            r"(?i)(union\s+select)",
            r"(?i)(or\s+['\"]?1['\"]?\s*=\s*['\"]?1)",
            r"(?i)(--\s)",
            r"(?i)(\/\*.*\*\/)",
            r"(?i)(xp_cmdshell)",
            r"(?i)(exec\s+\()"
        ]
        self.XSS_PATTERNS = [
            r"(?i)(<script>)",
            r"(?i)(javascript:)",
            r"(?i)(onload\=)",
            r"(?i)(onerror\=)",
            r"(?i)(alert\s*\()"
        ]

    def _check_rate_limit(self, ip: str) -> bool:
        # WHITELIST: Localhost & Docker Bridge (All 172.x ranges)
        if ip == "127.0.0.1" or ip.startswith("172."):
            return True
            
        if ip not in self._buckets:
            self._buckets[ip] = TokenBucket(self.RATE_LIMIT, self.CAPACITY)
        return self._buckets[ip].consume()

    def _is_malicious(self, content: str) -> bool:
        for pattern in self.SQLI_PATTERNS + self.XSS_PATTERNS:
            if re.search(pattern, content):
                security_logger.warning(f"THREAT DETECTED: Pattern '{pattern}' matched in payload.")
                return True
        return False

    async def dispatch(self, request: Request, call_next):
        client_ip = request.client.host if request.client else "unknown"

        # 1. Rate Limiting
        if not self._check_rate_limit(client_ip):
            security_logger.warning(f"RATE LIMIT EXCEEDED: IP {client_ip} blocked.")
            return Response(content="Rate limit exceeded", status_code=403)

        # 2. Payload Sanitization (Read body if present)
        # Note: Reading the body in middleware consumes the stream. 
        # We need to receive it, check it, and then make it available again if safe.
        # However, for simple JSON APIs, we can peek. Complexity rises with large files.
        # For this mission, we'll try to read typical JSON payloads.
        if request.method in ["POST", "PUT", "PATCH"]:
            try:
                # Read body
                body_bytes = await request.body()
                body_str = body_bytes.decode("utf-8", errors="ignore")
                
                if self._is_malicious(body_str):
                    security_logger.critical(f"SECURITY BREACH BLOCKED: SQLi/XSS from IP {client_ip}")
                    return Response(content="Forbidden: Malicious Payload Detected", status_code=403)
                
                # If safe, we need to allow downstream to read body again.
                # However, Starlette Request.body() caches the result so subsequent calls work.
                # But we consumed the stream from the underlying receive.
                # Since we used `await request.body()`, Starlette caches it.
            except Exception as e:
                security_logger.error(f"Error inspecting payload: {e}")
                # Fail open or closed? Security -> Fail closed usually, but let's be careful not to break valid reqs
                pass

        response = await call_next(request)
        return response
