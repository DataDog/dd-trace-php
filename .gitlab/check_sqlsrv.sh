#!/usr/bin/env bash

set +e  # Don't exit on error, we want to collect diagnostics

echo "=== SQL Server Diagnostics ==="

# Check if sqlsrv-integration is reachable
echo "1. Checking if SQL Server port is reachable..."
if timeout 5 bash -c "echo > /dev/tcp/sqlsrv-integration/1433" 2>/dev/null; then
    echo "✓ SQL Server port 1433 is open"
else
    echo "✗ SQL Server port 1433 is NOT reachable"
fi

# Try to connect via sqlcmd if available
echo ""
echo "2. Attempting SQL Server connection test..."
if command -v sqlcmd &> /dev/null; then
    sqlcmd -S sqlsrv-integration,1433 -U sa -P 'Password12!' -Q 'SELECT @@VERSION' -C 2>&1 || echo "✗ Connection test failed"
else
    echo "⊘ sqlcmd not available in this container"
fi

# DNS check
echo ""
echo "3. DNS resolution check..."
if command -v host &> /dev/null; then
    host sqlsrv-integration 2>&1 || echo "✗ DNS lookup failed"
elif command -v nslookup &> /dev/null; then
    nslookup sqlsrv-integration 2>&1 || echo "✗ DNS lookup failed"
else
    getent hosts sqlsrv-integration 2>&1 || echo "✗ DNS lookup failed"
fi

# Check if we can get Kubernetes pod logs
echo ""
echo "4. Attempting to fetch SQL Server container logs..."
if command -v kubectl &> /dev/null && [ -n "${CI_PROJECT_DIR}" ]; then
    POD_NAME=$(kubectl get pods -n gitlab-runner -l "ci-job-id=${CI_JOB_ID}" -o jsonpath='{.items[0].metadata.name}' 2>/dev/null)

    if [ -n "$POD_NAME" ]; then
        echo "Found pod: $POD_NAME"
        echo "--- SQL Server container logs (last 50 lines) ---"
        kubectl logs -n gitlab-runner "$POD_NAME" -c svc-3 --tail=50 2>&1 || echo "✗ Could not fetch container logs"
        echo "--- End of SQL Server logs ---"
    else
        echo "✗ Could not determine pod name"
    fi
else
    echo "⊘ kubectl not available or not in CI environment"
fi

# Network diagnostics
echo ""
echo "5. Network diagnostics..."
echo "Active connections:"
ss -tunap 2>/dev/null | grep -E "sqlsrv|1433" || netstat -tunap 2>/dev/null | grep -E "sqlsrv|1433" || echo "⊘ Network tools not available"

# Environment info
echo ""
echo "6. Environment information..."
echo "Hostname: $(hostname)"
echo "Pod IP: ${HOSTNAME:-unknown}"
echo "Service env vars:"
env | grep -i "sqlsrv\|sql\|mssql" | grep -v "PASSWORD" || echo "No SQL Server env vars found"

echo ""
echo "=== End SQL Server Diagnostics ==="
